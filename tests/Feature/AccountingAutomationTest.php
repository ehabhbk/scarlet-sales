<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Employee;
use App\Models\InventoryItem;
use App\Models\InventoryUnit;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Purchase;
use App\Models\Restaurant;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccountingAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_expense_is_posted_to_its_expense_account_and_selected_payment_account(): void
    {
        $restaurant = $this->createRestaurant();
        $user = $this->createUser($restaurant);
        $this->grantPermissions($user, ['create_expenses']);
        $expenseAccount = $this->createAccount($restaurant, '5001', 'مصروف تشغيل', 'expense');
        $cashAccount = $this->createAccount($restaurant, '1001', 'حساب النقد', 'asset');
        $this->configurePaymentMethod($restaurant, 'cash', $cashAccount);

        $this->actingAs($user, 'sanctum')->postJson('/api/expenses', [
            'category' => 'تشغيل',
            'account_id' => $expenseAccount->id,
            'amount' => 75,
            'expense_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'description' => 'شراء مستلزمات',
        ])->assertCreated();

        $entry = JournalEntry::where('restaurant_id', $restaurant->id)
            ->where('reference', 'EXPENSE-1')
            ->firstOrFail();
        $lines = JournalEntryLine::where('journal_entry_id', $entry->id)->get();

        $this->assertSame(75.0, (float) $lines->where('account_id', $expenseAccount->id)->sum('debit'));
        $this->assertSame(75.0, (float) $lines->where('account_id', $cashAccount->id)->sum('credit'));
    }

    public function test_payment_method_can_be_linked_to_an_active_account_of_any_type(): void
    {
        $restaurant = $this->createRestaurant();
        $user = $this->createUser($restaurant);
        $this->grantPermissions($user, ['edit_settings']);
        $revenueAccount = $this->createAccount($restaurant, '4001', 'إيرادات أخرى', 'revenue');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/settings', [
                'group' => 'pos',
                'key' => 'payment_methods',
                'type' => 'json',
                'value' => json_encode([[
                    'value' => 'cash',
                    'label' => 'نقدي',
                    'account_id' => $revenueAccount->id,
                ]]),
            ])
            ->assertCreated();

        $this->assertDatabaseHas('settings', [
            'restaurant_id' => $restaurant->id,
            'key' => 'payment_methods',
        ]);
        $this->assertSame(
            $revenueAccount->id,
            app(\App\Services\JournalPostingService::class)->paymentAccount($restaurant->id, 'cash')->id,
        );
    }

    public function test_purchase_posts_only_paid_amount_and_later_payments_are_posted_separately(): void
    {
        $restaurant = $this->createRestaurant();
        $user = $this->createUser($restaurant);
        $this->grantPermissions($user, ['create_purchases']);
        $supplier = Supplier::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'مورد تجريبي',
        ]);
        $unit = InventoryUnit::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'قطعة',
            'abbreviation' => 'pc',
        ]);
        $item = InventoryItem::create([
            'restaurant_id' => $restaurant->id,
            'unit_id' => $unit->id,
            'name' => 'صنف تجريبي',
        ]);
        $purchaseAccount = $this->createAccount($restaurant, '5002', 'حساب المشتريات', 'expense');
        $cashAccount = $this->createAccount($restaurant, '1002', 'حساب فوري', 'asset');
        $this->configurePaymentMethod($restaurant, 'fawry', $cashAccount);

        $this->actingAs($user, 'sanctum')->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'account_id' => $purchaseAccount->id,
            'purchase_number' => 'PO-TEST-1',
            'status' => 'ordered',
            'subtotal' => 100,
            'total_amount' => 100,
            'amount_paid' => 30,
            'payment_method' => 'fawry',
            'order_date' => now()->toDateString(),
            'items' => [[
                'inventory_item_id' => $item->id,
                'quantity' => 1,
                'unit_cost' => 100,
            ]],
        ])->assertCreated();

        $purchase = Purchase::where('purchase_number', 'PO-TEST-1')->firstOrFail();
        $firstPayment = $purchase->payments()->firstOrFail();
        $firstPaymentEntry = JournalEntry::where('reference', 'PURCHASE-'.$purchase->id.'-PAYMENT-'.$firstPayment->id)->firstOrFail();
        $this->assertSame(
            30.0,
            (float) JournalEntryLine::where('journal_entry_id', $firstPaymentEntry->id)
                ->where('account_id', $purchaseAccount->id)
                ->sum('debit')
        );
        $this->assertSame(
            30.0,
            (float) JournalEntryLine::where('journal_entry_id', $firstPaymentEntry->id)
                ->where('account_id', $cashAccount->id)
                ->sum('credit')
        );

        $this->postJson('/api/purchases/'.$purchase->id.'/payment', [
            'amount' => 70,
            'method' => 'fawry',
        ])->assertCreated();

        $purchase->refresh();
        $this->assertSame('paid', $purchase->payment_status);
        $this->assertSame(100.0, (float) $purchase->amount_paid);
        $this->assertSame(2, JournalEntry::where('restaurant_id', $restaurant->id)
            ->where('reference', 'like', 'PURCHASE-'.$purchase->id.'-%')
            ->count());
    }

    public function test_monthly_salary_is_posted_only_after_payment_is_requested(): void
    {
        $restaurant = $this->createRestaurant();
        $user = $this->createUser($restaurant);
        $this->grantPermissions($user, ['create_employees', 'pay_employee_wages']);
        $payAccount = $this->createAccount($restaurant, '5003', 'رواتب الموظفين', 'expense');
        $cashAccount = $this->createAccount($restaurant, '1003', 'الخزينة', 'asset');
        $this->configurePaymentMethod($restaurant, 'cash', $cashAccount);

        $this->actingAs($user, 'sanctum')->postJson('/api/employees', [
            'employee_id' => 'EMP-SALARY',
            'name' => 'موظف براتب',
            'job_title' => 'محاسب',
            'hire_date' => now()->toDateString(),
            'salary' => 1200,
            'pay_type' => 'monthly',
            'pay_account_id' => $payAccount->id,
        ])->assertCreated();

        $employee = Employee::where('employee_id', 'EMP-SALARY')->firstOrFail();
        $this->assertSame(0, JournalEntry::where('reference', 'like', 'SALARY-'.$employee->id.'-%')->count());

        $this->postJson('/api/employees/'.$employee->id.'/pay-salary', [
            'payment_method' => 'cash',
        ])->assertOk();

        $entry = JournalEntry::where('reference', 'SALARY-'.$employee->id.'-'.now()->format('Y-m'))->firstOrFail();
        $lines = JournalEntryLine::where('journal_entry_id', $entry->id)->get();
        $this->assertSame(1200.0, (float) $lines->where('account_id', $payAccount->id)->sum('debit'));
        $this->assertSame(1200.0, (float) $lines->where('account_id', $cashAccount->id)->sum('credit'));

        $this->postJson('/api/employees/'.$employee->id.'/pay-salary', [
            'payment_method' => 'cash',
        ])->assertStatus(409);
    }

    public function test_expenses_and_purchases_reject_unconfigured_payment_methods(): void
    {
        $restaurant = $this->createRestaurant();
        $user = $this->createUser($restaurant);
        $this->grantPermissions($user, ['create_expenses', 'create_purchases']);
        $expenseAccount = $this->createAccount($restaurant, '5004', 'مصروف تشغيل', 'expense');
        $cashAccount = $this->createAccount($restaurant, '1004', 'الخزينة', 'asset');
        $this->configurePaymentMethod($restaurant, 'cash', $cashAccount);

        $this->actingAs($user, 'sanctum')->postJson('/api/expenses', [
            'category' => 'تشغيل',
            'account_id' => $expenseAccount->id,
            'amount' => 20,
            'expense_date' => now()->toDateString(),
            'payment_method' => 'unknown',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('payment_method');

        $supplier = Supplier::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'مورد تجريبي',
        ]);
        $unit = InventoryUnit::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'قطعة',
            'abbreviation' => 'pc',
        ]);
        $item = InventoryItem::create([
            'restaurant_id' => $restaurant->id,
            'unit_id' => $unit->id,
            'name' => 'صنف تجريبي',
        ]);

        $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'account_id' => $expenseAccount->id,
            'purchase_number' => 'PO-UNCONFIGURED',
            'status' => 'ordered',
            'subtotal' => 100,
            'total_amount' => 100,
            'amount_paid' => 0,
            'payment_method' => 'unknown',
            'order_date' => now()->toDateString(),
            'items' => [[
                'inventory_item_id' => $item->id,
                'quantity' => 1,
                'unit_cost' => 100,
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('payment_method');

        $this->assertDatabaseMissing('purchases', ['purchase_number' => 'PO-UNCONFIGURED']);
        $this->assertSame(0, JournalEntry::where('restaurant_id', $restaurant->id)->count());
    }

    private function createRestaurant(): Restaurant
    {
        return Restaurant::create([
            'name' => 'Test Restaurant',
            'slug' => Str::uuid()->toString(),
            'tax_rate' => 0,
        ]);
    }

    private function createUser(Restaurant $restaurant): User
    {
        return User::factory()->create([
            'restaurant_id' => $restaurant->id,
            'username' => Str::uuid()->toString(),
        ]);
    }

    private function createAccount(Restaurant $restaurant, string $code, string $name, string $type): Account
    {
        return Account::create([
            'restaurant_id' => $restaurant->id,
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'is_active' => true,
        ]);
    }

    private function configurePaymentMethod(Restaurant $restaurant, string $method, Account $account): void
    {
        Setting::create([
            'restaurant_id' => $restaurant->id,
            'group' => 'pos',
            'key' => 'payment_methods',
            'type' => 'json',
            'value' => json_encode([['value' => $method, 'account_id' => $account->id]]),
        ]);
    }

    private function grantPermissions(User $user, array $permissionNames): void
    {
        $permissions = collect($permissionNames)->map(fn (string $name) => Permission::firstOrCreate([
            'name' => $name,
            'guard_name' => 'sanctum',
        ]));
        $role = Role::create(['name' => 'Accounting Test '.$user->id, 'guard_name' => 'sanctum']);
        $role->syncPermissions($permissions);
        $user->assignRole($role);
    }
}
