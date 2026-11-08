<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\KitchenStation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderKitchenStation;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\ShiftGroup;
use App\Models\User;
use App\Services\CashierAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CashierAccountingTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_cashier_creates_linked_vault_and_pay_accounts(): void
    {
        $restaurant = $this->createRestaurant();
        $admin = $this->createUser($restaurant);
        $adminRole = Role::create(['name' => 'Super Admin', 'guard_name' => 'sanctum']);
        $admin->assignRole($adminRole);
        $cashierRole = Role::create(['name' => 'Cashier', 'guard_name' => 'sanctum']);
        foreach (['view_pos', 'create_orders'] as $permission) {
            $cashierRole->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'sanctum']));
        }

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/users', [
                'name' => 'Ehab Cashier',
                'username' => 'ehab.cashier',
                'password' => 'secret123',
                'role' => 'Cashier',
            ])
            ->assertCreated()
            ->assertJsonPath('user.revenue_account.name', 'إيرادات Ehab Cashier')
            ->assertJsonPath('user.pay_account.name', 'مصروف أجور Ehab Cashier');

        $user = User::where('username', 'ehab.cashier')->firstOrFail();
        $this->assertNotNull($user->revenue_account_id);
        $this->assertNotNull($user->pay_account_id);
        $this->assertSame(
            (int) Account::where('restaurant_id', $restaurant->id)->where('code', '4000')->value('id'),
            (int) Account::whereKey($user->revenue_account_id)->value('parent_id')
        );
        $this->assertSame(
            (int) Account::where('restaurant_id', $restaurant->id)->where('code', '5000')->value('id'),
            (int) Account::whereKey($user->pay_account_id)->value('parent_id')
        );
    }

    public function test_registration_also_provisions_accounts_for_a_cashier_role(): void
    {
        $restaurant = $this->createRestaurant();
        $cashierRole = Role::create(['name' => 'Cashier', 'guard_name' => 'sanctum']);
        foreach (['view_pos', 'create_orders'] as $permission) {
            $cashierRole->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'sanctum']));
        }

        $this->postJson('/api/auth/register', [
            'name' => 'Registered Cashier',
            'username' => 'registered.cashier',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'restaurant_id' => $restaurant->id,
            'role' => 'Cashier',
        ])
            ->assertCreated()
            ->assertJsonPath('user.revenue_account.name', 'إيرادات Registered Cashier')
            ->assertJsonPath('user.pay_account.name', 'مصروف أجور Registered Cashier');
    }

    public function test_creating_a_non_cashier_also_links_a_pay_account(): void
    {
        $restaurant = $this->createRestaurant();
        $admin = $this->createUser($restaurant);
        $admin->assignRole(Role::create(['name' => 'Super Admin', 'guard_name' => 'sanctum']));
        Role::create(['name' => 'Manager', 'guard_name' => 'sanctum']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/users', [
                'name' => 'Restaurant Manager',
                'username' => 'restaurant.manager',
                'password' => 'secret123',
                'role' => 'Manager',
            ])
            ->assertCreated()
            ->assertJsonPath('user.pay_account.type', 'expense');

        $user = User::where('username', 'restaurant.manager')->firstOrFail();
        $this->assertNotNull($user->pay_account_id);
        $this->assertNull($user->revenue_account_id);
    }

    public function test_creating_a_cashier_keeps_the_selected_revenue_account_linked(): void
    {
        $restaurant = $this->createRestaurant();
        $admin = $this->createUser($restaurant);
        $admin->assignRole(Role::create(['name' => 'Super Admin', 'guard_name' => 'sanctum']));
        $cashierRole = Role::create(['name' => 'Cashier', 'guard_name' => 'sanctum']);
        foreach (['view_pos', 'create_orders'] as $permission) {
            $cashierRole->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'sanctum']));
        }
        $parent = Account::create([
            'restaurant_id' => $restaurant->id,
            'code' => '4000',
            'name' => 'إيرادات المبيعات',
            'type' => 'revenue',
        ]);
        $revenue = Account::create([
            'restaurant_id' => $restaurant->id,
            'parent_id' => $parent->id,
            'code' => '4199',
            'name' => 'إيراد كاشير مخصص',
            'type' => 'revenue',
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/users', [
                'name' => 'Linked Cashier',
                'username' => 'linked.cashier',
                'password' => 'secret123',
                'role' => 'Cashier',
                'revenue_account_id' => $revenue->id,
            ])
            ->assertCreated()
            ->assertJsonPath('user.revenue_account_id', $revenue->id)
            ->assertJsonPath('user.revenue_account.name', 'إيراد كاشير مخصص');
    }

    public function test_opening_a_shift_links_selected_daily_and_percentage_employees(): void
    {
        $restaurant = $this->createRestaurant();
        $cashier = $this->createUser($restaurant);
        $this->grantPermissions($cashier, ['open_shift', 'manage_shift_employees']);
        $cashier->assignRole(Role::create(['name' => 'Cashier', 'guard_name' => 'sanctum']));
        $employee = Employee::create([
            'restaurant_id' => $restaurant->id,
            'employee_id' => 'EMP-SHIFT-'.$cashier->id,
            'name' => 'Shift Employee',
            'job_title' => 'Waiter',
            'hire_date' => now()->toDateString(),
            'pay_type' => 'daily',
            'pay_rate' => 500,
            'pay_auto' => true,
            'status' => 'active',
        ]);

        $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/shifts/open', [
                'employee_ids' => [$employee->id],
                'name' => 'وردية اختبار',
                'shift_date' => now()->toDateString(),
                'start_time' => '08:00',
                'end_time' => '16:00',
            ])
            ->assertCreated()
            ->assertJsonPath('shift.status', 'open')
            ->assertJsonPath('shift.employees.0.id', $employee->id);

        $this->getJson('/api/shifts/current')
            ->assertOk()
            ->assertJsonPath('shift.status', 'open');

        $this->assertDatabaseHas('shift_group_employees', [
            'employee_id' => $employee->id,
            'payment_method' => null,
        ]);
    }

    public function test_shift_options_returns_eligible_employees_to_users_with_permission(): void
    {
        $restaurant = $this->createRestaurant();
        $user = $this->createUser($restaurant);
        $this->grantPermissions($user, ['open_shift', 'manage_shift_employees']);
        $employee = Employee::create([
            'restaurant_id' => $restaurant->id,
            'employee_id' => 'EMP-OPTIONS-'.$user->id,
            'name' => 'Eligible Shift Employee',
            'job_title' => 'Waiter',
            'hire_date' => now()->toDateString(),
            'pay_type' => 'daily',
            'pay_rate' => 500,
            'pay_auto' => true,
            'status' => 'active',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/shifts/options')
            ->assertOk()
            ->assertJsonPath('permissions.manage_shift_employees', true)
            ->assertJsonPath('employees.0.id', $employee->id);
    }

    public function test_permission_list_is_arabic_and_starts_with_shift_actions(): void
    {
        $restaurant = $this->createRestaurant();
        $user = $this->createUser($restaurant);
        $this->grantPermissions($user, ['view_roles']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/permissions')
            ->assertOk();

        $permissions = collect($response->json('permissions'));

        $this->assertSame('فتح وردية', $permissions->firstWhere('name', 'open_shift')['label'] ?? null);
        $this->assertSame('إغلاق وردية', $permissions->firstWhere('name', 'close_shift')['label'] ?? null);
        $this->assertSame(
            'عرض نقطة البيع',
            $permissions->firstWhere('name', 'view_pos')['label'] ?? null
        );
    }

    public function test_pos_revenue_is_hidden_when_the_cashier_has_no_open_shift(): void
    {
        $restaurant = $this->createRestaurant();
        $sellerWithRevenueAccess = $this->createUser($restaurant);
        $this->grantPermissions($sellerWithRevenueAccess, ['create_orders', 'view_pos']);
        $this->createPaidOrder($restaurant, $sellerWithRevenueAccess);

        $this->actingAs($sellerWithRevenueAccess, 'sanctum')
            ->getJson('/api/shifts/current')
            ->assertOk()
            ->assertJsonPath('shift', null)
            ->assertJsonPath('revenue', null)
            ->assertJsonPath('orders_count', null);

        $sellerWithoutRevenueAccess = $this->createUser($restaurant);
        $this->grantPermissions($sellerWithoutRevenueAccess, ['create_orders']);
        $this->createPaidOrder($restaurant, $sellerWithoutRevenueAccess);

        $this->actingAs($sellerWithoutRevenueAccess, 'sanctum')
            ->getJson('/api/shifts/current')
            ->assertOk()
            ->assertJsonPath('shift', null)
            ->assertJsonPath('revenue', null)
            ->assertJsonPath('orders_count', null);

        $this->getJson('/api/products/available')->assertOk();
    }

    public function test_cashiers_join_the_shared_shift_from_their_own_sessions(): void
    {
        $restaurant = $this->createRestaurant();
        $manager = $this->createUser($restaurant);
        $this->grantPermissions($manager, [
            'open_shift',
            'manage_shift_employees',
            'view_all_shifts',
            'view_pos',
        ]);
        $cashierRole = Role::create(['name' => 'Cashier', 'guard_name' => 'sanctum']);
        $cashiers = collect([
            $this->createUser($restaurant),
            $this->createUser($restaurant),
        ]);
        $cashiers->each(fn (User $cashier) => $this->grantPermissions($cashier, ['open_shift']));
        $cashiers->each(fn (User $cashier) => $cashier->assignRole($cashierRole));
        $employees = $cashiers->map(fn (User $cashier, int $index) => Employee::create([
            'restaurant_id' => $restaurant->id,
            'employee_id' => 'EMP-GROUP-'.$cashier->id,
            'name' => 'Group Employee '.$index,
            'job_title' => 'Waiter',
            'hire_date' => now()->toDateString(),
            'pay_type' => 'daily',
            'pay_rate' => 100,
            'pay_auto' => true,
            'status' => 'active',
        ]));

        $response = $this->actingAs($manager, 'sanctum')->postJson('/api/shifts/open', [
            'employee_ids' => $employees->pluck('id')->all(),
            'name' => 'وردية جماعية',
            'shift_date' => now()->toDateString(),
            'start_time' => '08:00',
            'end_time' => '16:00',
            'opening_cash' => 500,
        ])->assertCreated()
            ->assertJsonPath('shift.user_id', $manager->id)
            ->assertJsonPath('shift.status', 'open')
            ->assertJsonCount(1, 'shifts');

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/shifts/current')
            ->assertOk()
            ->assertJsonPath('shift.status', 'open')
            ->assertJsonPath('shift.user_id', $manager->id)
            ->assertJsonPath('revenue.total', 0);

        $this->assertDatabaseHas('shift_group_employees', [
            'shift_group_id' => $response->json('group.id'),
            'employee_id' => $employees[0]->id,
        ]);
        $this->assertDatabaseHas('shift_group_employees', [
            'shift_group_id' => $response->json('group.id'),
            'employee_id' => $employees[1]->id,
        ]);

        // The shift is global: every POS user joins the same open shift.
        $shifts = $cashiers->map(function (User $cashier) {
            return $this->actingAs($cashier, 'sanctum')
                ->getJson('/api/shifts/current')
                ->assertOk()
                ->json('shift');
        })->all();
        $this->assertCount(2, $shifts);
        $this->assertSame($shifts[0]['id'], $shifts[1]['id']);
        $this->assertSame($response->json('shift.id'), $shifts[0]['id']);
        $this->assertSame($manager->id, $shifts[0]['user_id']);
        $this->assertSame($shifts[0]['shift_group_id'], $shifts[1]['shift_group_id']);
        foreach ($shifts as $shift) {
            $this->assertSame(
                $employees->pluck('id')->all(),
                array_column($shift['employees'], 'id')
            );
            $this->assertDatabaseHas('shifts', [
                'id' => $shift['id'],
                'user_id' => $manager->id,
                'shift_group_id' => $shift['shift_group_id'],
            ]);
        }
        $this->assertSame(
            [$employees[0]->id, $employees[1]->id],
            $response->json('group.employees') ? array_column($response->json('group.employees'), 'id') : $employees->pluck('id')->all()
        );
        // Only the opener (or a user with view_all_shifts) may inspect the shift.
        $this->actingAs($cashiers[0], 'sanctum')
            ->getJson('/api/shifts/'.$shifts[0]['id'])
            ->assertForbidden();
        $this->deleteJson('/api/shifts/'.$shifts[0]['id'])->assertForbidden();
        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/shifts/'.$shifts[0]['id'])
            ->assertOk();
    }

    public function test_orders_are_scoped_by_cashier_and_payment_method_change_requires_edit_permission(): void
    {
        $restaurant = $this->createRestaurant();
        $cashier = $this->createUser($restaurant);
        $manager = $this->createUser($restaurant);
        $this->grantPermissions($cashier, ['view_orders']);
        $this->grantPermissions($manager, ['view_orders', 'view_all_orders', 'edit_orders', 'edit_order_payment']);
        $firstOrder = $this->createPaidOrder($restaurant, $cashier);
        $secondOrder = $this->createPaidOrder($restaurant, $this->createUser($restaurant));
        $cashAccount = Account::create([
            'restaurant_id' => $restaurant->id,
            'code' => '1025',
            'name' => 'خزينة التحويل',
            'type' => 'asset',
            'is_active' => true,
        ]);
        Setting::create([
            'restaurant_id' => $restaurant->id,
            'group' => 'pos',
            'key' => 'payment_methods',
            'value' => json_encode([
                ['value' => 'bankak', 'label' => 'بنكك', 'account_id' => $cashAccount->id],
            ]),
            'type' => 'json',
        ]);

        $this->actingAs($cashier, 'sanctum')
            ->getJson('/api/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->getJson('/api/orders/'.$secondOrder->id)->assertForbidden();

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/orders')
            ->assertOk()
            ->assertJsonCount(2, 'data');
        $this->putJson('/api/orders/'.$firstOrder->id, ['payment_method' => 'bankak'])
            ->assertOk();

        $this->assertSame('bankak', $firstOrder->fresh()->payment_method);
        $this->assertSame('bankak', Payment::where('paymentable_id', $firstOrder->id)->value('method'));
        $this->assertSame(250.0, (float) $firstOrder->fresh()->amount_paid);
    }

    public function test_shift_report_shows_revenue_per_cashier_without_exposing_other_restaurants(): void
    {
        $restaurant = $this->createRestaurant();
        $otherRestaurant = $this->createRestaurant();
        $manager = $this->createUser($restaurant);
        $this->grantPermissions($manager, ['view_all_shifts']);
        $cashiers = collect([
            $this->createUser($restaurant),
            $this->createUser($restaurant),
        ]);
        $group = ShiftGroup::create([
            'restaurant_id' => $restaurant->id,
            'opened_by' => $manager->id,
            'name' => 'وردية التقرير',
            'shift_date' => now()->toDateString(),
        ]);
        $cashiers->each(function (User $cashier) use ($restaurant, $group): void {
            $shift = Shift::create([
                'restaurant_id' => $restaurant->id,
                'shift_group_id' => $group->id,
                'user_id' => $cashier->id,
                'name' => $group->name,
                'shift_date' => now()->toDateString(),
                'start_time' => '08:00:00',
                'end_time' => '16:00:00',
                'status' => 'closed',
            ]);
            $this->createPaidOrder($restaurant, $cashier)->update(['shift_id' => $shift->id]);
        });
        $otherCashier = $this->createUser($otherRestaurant);
        $otherShift = Shift::create([
            'restaurant_id' => $otherRestaurant->id,
            'user_id' => $otherCashier->id,
            'name' => 'وردية خارجية',
            'shift_date' => now()->toDateString(),
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'status' => 'closed',
        ]);
        $this->createPaidOrder($otherRestaurant, $otherCashier)->update(['shift_id' => $otherShift->id]);

        $response = $this->actingAs($manager, 'sanctum')
            ->getJson('/api/reports/shifts?from='.now()->toDateString().'&to='.now()->toDateString())
            ->assertOk()
            ->assertJsonCount(2, 'shifts');

        $this->assertEquals([250.0, 250.0], collect($response->json('shifts'))->pluck('total')->all());
        $this->assertEquals([1, 1], collect($response->json('shifts'))->pluck('orders_count')->all());
    }

    public function test_employee_wages_are_paid_separately_and_shift_closing_posts_revenue_only(): void
    {
        $restaurant = $this->createRestaurant();
        $cashier = $this->createUser($restaurant);
        $cashierRole = Role::create(['name' => 'Cashier', 'guard_name' => 'sanctum']);
        $cashier->assignRole($cashierRole);
        $this->grantPermissions($cashier, ['close_shift', 'pay_employee_wages', 'view_pos', 'create_orders']);
        app(CashierAccountService::class)->ensureFor($cashier);

        $cashAccount = Account::create([
            'restaurant_id' => $restaurant->id,
            'code' => '1020',
            'name' => 'إيداع نقدي',
            'type' => 'asset',
        ]);
        $fawryAccount = Account::create([
            'restaurant_id' => $restaurant->id,
            'code' => '1030',
            'name' => 'فوري',
            'type' => 'asset',
        ]);
        Setting::create([
            'restaurant_id' => $restaurant->id,
            'group' => 'pos',
            'key' => 'payment_methods',
            'type' => 'json',
            'value' => json_encode([
                ['value' => 'cash', 'account_id' => $cashAccount->id],
                ['value' => 'fawry', 'account_id' => $fawryAccount->id],
            ]),
        ]);
        $shift = Shift::create([
            'restaurant_id' => $restaurant->id,
            'user_id' => $cashier->id,
            'name' => 'صباحية',
            'shift_date' => now()->toDateString(),
            'start_time' => '08:00:00',
            'end_time' => '23:59:00',
            'status' => 'open',
            'opening_cash' => 0,
            'expected_cash' => 0,
            'actual_cash' => 0,
            'difference' => 0,
        ]);
        $employee = Employee::create([
            'restaurant_id' => $restaurant->id,
            'user_id' => $cashier->id,
            'employee_id' => 'EMP-'.$cashier->id,
            'name' => $cashier->name,
            'job_title' => 'Cashier',
            'hire_date' => now()->toDateString(),
            'salary' => 0,
            'pay_type' => 'daily',
            'pay_rate' => 30,
            'pay_auto' => true,
            'status' => 'active',
        ]);
        $commissionExpense = Account::create([
            'restaurant_id' => $restaurant->id,
            'code' => '5400',
            'name' => 'عمولات الموظفين',
            'type' => 'expense',
        ]);
        $commissionEmployee = Employee::create([
            'restaurant_id' => $restaurant->id,
            'employee_id' => 'EMP-COMMISSION-'.$cashier->id,
            'name' => 'موظف عمولة',
            'job_title' => 'Waiter',
            'hire_date' => now()->toDateString(),
            'salary' => 0,
            'pay_type' => 'percentage',
            'pay_rate' => 10,
            'pay_account_id' => $commissionExpense->id,
            'pay_auto' => true,
            'status' => 'active',
        ]);
        $shift->employees()->attach([$employee->id, $commissionEmployee->id]);
        foreach ([
            ['cash', 100],
            ['fawry', 200],
        ] as $index => [$method, $amount]) {
            $order = Order::create([
                'restaurant_id' => $restaurant->id,
                'cashier_id' => $cashier->id,
                'shift_id' => $shift->id,
                'order_number' => 'TEST-'.$index,
                'type' => 'takeaway',
                'status' => 'completed',
                'payment_status' => 'paid',
                'payment_method' => $method,
                'subtotal' => $amount,
                'total_amount' => $amount,
                'amount_paid' => $amount,
            ]);
            Payment::create([
                'restaurant_id' => $restaurant->id,
                'paymentable_type' => Order::class,
                'paymentable_id' => $order->id,
                'amount' => $amount,
                'method' => $method,
                'paid_at' => now(),
                'user_id' => $cashier->id,
            ]);
        }

        $this->actingAs($cashier, 'sanctum')
            ->getJson('/api/shifts/'.$shift->id.'/employee-wages')
            ->assertOk()
            ->assertJsonCount(2, 'employees');

        $this->actingAs($cashier, 'sanctum')
            ->putJson('/api/shifts/'.$shift->id, ['status' => 'closed'])
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'لا يمكن إغلاق الوردية قبل تسوية أجور الموظفين. تبقى الموظفون: '
                    .$cashier->name.'، موظف عمولة لم يُعطَوا أجورهم.'
            )
            ->assertJsonPath(
                'errors.status.0',
                'لا يمكن إغلاق الوردية قبل تسوية أجور الموظفين. تبقى الموظفون: '
                    .$cashier->name.'، موظف عمولة لم يُعطَوا أجورهم.'
            );

        $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/shifts/'.$shift->id.'/employee-wages', [
                'employee_id' => $employee->id,
                'payment_method' => 'cash',
            ])
            ->assertOk();
        $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/shifts/'.$shift->id.'/employee-wages', [
                'employee_id' => $employee->id,
                'payment_method' => 'cash',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('employee_id');
        $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/shifts/'.$shift->id.'/employee-wages', [
                'employee_id' => $commissionEmployee->id,
                'payment_method' => 'fawry',
            ])
            ->assertOk();

        $this->actingAs($cashier, 'sanctum')
            ->getJson('/api/shifts/'.$shift->id.'/employee-wages')
            ->assertOk()
            ->assertJsonCount(0, 'employees');

        $this->actingAs($cashier, 'sanctum')
            ->putJson('/api/shifts/'.$shift->id, ['status' => 'closed', 'actual_cash' => 100])
            ->assertOk()
            ->assertJsonPath('shift.status', 'closed')
            ->assertJsonPath('message', 'تم إغلاق الوردية وإنشاء قيود الوردية بنجاح');

        $this->actingAs($cashier, 'sanctum')
            ->getJson('/api/shifts/current')
            ->assertOk()
            ->assertJsonPath('shift', null)
            ->assertJsonPath('revenue', null)
            ->assertJsonPath('orders_count', null);

        // Wages are merged into the shift expense details and its total, while
        // the net total subtracts them only once.
        $shiftReport = $this->actingAs($cashier, 'sanctum')
            ->getJson('/api/shifts/'.$shift->id.'/revenue')
            ->assertOk()
            ->assertJsonPath('expenses_total', 60)
            ->assertJsonPath('wages_total', 60)
            ->assertJsonPath('net_total', 240);
        $this->assertCount(2, $shiftReport->json('expenses'));
        $this->assertSame(
            ['wage'],
            collect($shiftReport->json('expenses'))->pluck('source')->unique()->values()->all(),
        );

        $entry = JournalEntry::where('reference', 'SHIFT-'.$shift->id)->firstOrFail();
        $lines = JournalEntryLine::where('journal_entry_id', $entry->id)->get();
        $this->assertSame(360.0, (float) $lines->sum('debit'));
        $this->assertSame(360.0, (float) $lines->sum('credit'));
        $this->assertSame(300.0, (float) $lines->where('account_id', $cashier->revenue_account_id)->sum('credit'));
        $this->assertSame(100.0, (float) $lines->where('account_id', $cashAccount->id)->sum('debit'));
        $this->assertSame(30.0, (float) $lines->where('account_id', $cashAccount->id)->sum('credit'));
        $this->assertSame(200.0, (float) $lines->where('account_id', $fawryAccount->id)->sum('debit'));
        $this->assertSame(30.0, (float) $lines->where('account_id', $fawryAccount->id)->sum('credit'));
        $this->assertSame(30.0, (float) $lines->where('account_id', $cashier->pay_account_id)->sum('debit'));
        $this->assertSame(30.0, (float) $lines->where('account_id', $commissionExpense->id)->sum('debit'));

        $this->putJson('/api/shifts/'.$shift->id, [
            'status' => 'closed',
            'actual_cash' => 100,
        ])->assertUnprocessable();
        $this->assertSame(1, JournalEntry::where('reference', 'SHIFT-'.$shift->id)->count());
    }

    public function test_shifts_without_wage_obligations_close_and_finish_the_group(): void
    {
        $restaurant = $this->createRestaurant();
        $cashier = $this->createUser($restaurant);
        $this->grantPermissions($cashier, ['open_shift', 'close_shift']);
        $group = ShiftGroup::create([
            'restaurant_id' => $restaurant->id,
            'opened_by' => $cashier->id,
            'name' => 'وردية تجريبية',
            'shift_date' => now()->toDateString(),
            'status' => 'open',
        ]);
        $shift = Shift::create([
            'restaurant_id' => $restaurant->id,
            'shift_group_id' => $group->id,
            'user_id' => $cashier->id,
            'name' => 'وردية بدون موظفين بأجر تلقائي',
            'shift_date' => now()->toDateString(),
            'start_time' => '08:00:00',
            'end_time' => '23:59:00',
            'status' => 'open',
            'opening_cash' => 0,
            'expected_cash' => 0,
            'actual_cash' => 0,
            'difference' => 0,
        ]);

        $this->actingAs($cashier, 'sanctum')
            ->putJson('/api/shifts/'.$shift->id, ['status' => 'closed'])
            ->assertOk()
            ->assertJsonPath('shift.status', 'closed')
            ->assertJsonPath('message', 'تم إغلاق الوردية بنجاح');

        $this->assertDatabaseHas('shift_groups', ['id' => $group->id, 'status' => 'closed']);
        $this->postJson('/api/shifts/open', [
            'name' => 'الوردية التالية',
            'shift_date' => now()->toDateString(),
            'start_time' => '09:00',
        ])->assertCreated()
            ->assertJsonPath('group.status', 'open');
        $this->assertDatabaseCount('shift_groups', 2);
    }

    public function test_percentage_wage_uses_only_the_employee_assigned_to_the_shift(): void
    {
        $restaurant = $this->createRestaurant();
        $cashier = $this->createUser($restaurant);
        $cashier->assignRole(Role::create(['name' => 'Cashier', 'guard_name' => 'sanctum']));
        $this->grantPermissions($cashier, ['close_shift', 'pay_employee_wages', 'view_pos', 'create_orders']);
        app(CashierAccountService::class)->ensureFor($cashier);
        $cashAccount = Account::create([
            'restaurant_id' => $restaurant->id,
            'code' => '1021',
            'name' => 'حساب النقد',
            'type' => 'asset',
        ]);
        Setting::create([
            'restaurant_id' => $restaurant->id,
            'group' => 'pos',
            'key' => 'payment_methods',
            'value' => json_encode([
                ['value' => 'cash', 'label' => 'نقدي', 'account_id' => $cashAccount->id],
            ]),
            'type' => 'json',
        ]);

        $employee = Employee::create([
            'restaurant_id' => $restaurant->id,
            'user_id' => $cashier->id,
            'employee_id' => 'EMP-PERCENT-'.$cashier->id,
            'name' => $cashier->name,
            'job_title' => 'Cashier',
            'hire_date' => now()->toDateString(),
            'pay_type' => 'percentage',
            'pay_rate' => 10,
            'pay_account_id' => $cashier->pay_account_id,
            'pay_auto' => true,
            'status' => 'active',
        ]);
        $unassignedEmployee = Employee::create([
            'restaurant_id' => $restaurant->id,
            'employee_id' => 'EMP-UNASSIGNED-'.$cashier->id,
            'name' => 'Unassigned employee',
            'job_title' => 'Waiter',
            'hire_date' => now()->toDateString(),
            'pay_type' => 'percentage',
            'pay_rate' => 20,
            'pay_account_id' => $cashier->pay_account_id,
            'pay_auto' => true,
            'status' => 'active',
        ]);
        $shift = Shift::create([
            'restaurant_id' => $restaurant->id,
            'user_id' => $cashier->id,
            'employee_id' => $employee->id,
            'name' => 'مسائية',
            'shift_date' => now()->toDateString(),
            'start_time' => '08:00:00',
            'end_time' => '23:59:00',
            'status' => 'open',
            'opening_cash' => 0,
            'expected_cash' => 0,
            'actual_cash' => 0,
            'difference' => 0,
        ]);
        $order = Order::create([
            'restaurant_id' => $restaurant->id,
            'cashier_id' => $cashier->id,
            'shift_id' => $shift->id,
            'order_number' => 'PERCENT-'.$shift->id,
            'type' => 'takeaway',
            'status' => 'completed',
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'subtotal' => 500,
            'total_amount' => 500,
            'amount_paid' => 500,
        ]);
        Payment::create([
            'restaurant_id' => $restaurant->id,
            'paymentable_type' => Order::class,
            'paymentable_id' => $order->id,
            'amount' => 500,
            'method' => 'cash',
            'paid_at' => now(),
            'user_id' => $cashier->id,
        ]);

        $this->actingAs($cashier, 'sanctum')
            ->getJson('/api/shifts/'.$shift->id.'/employee-wages')
            ->assertOk()
            ->assertJsonPath('employees.0.amount', 50);
        $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/shifts/'.$shift->id.'/employee-wages', [
                'employee_id' => $employee->id,
                'payment_method' => 'cash',
            ])
            ->assertOk();
        $this->actingAs($cashier, 'sanctum')
            ->putJson('/api/shifts/'.$shift->id, ['status' => 'closed'])
            ->assertOk();

        $shiftEntry = JournalEntry::where('reference', 'SHIFT-'.$shift->id)->firstOrFail();
        $wageLines = JournalEntryLine::where('journal_entry_id', $shiftEntry->id)->get();
        $this->assertSame(
            50.0,
            (float) $wageLines->where('account_id', $cashier->pay_account_id)->sum('debit')
        );
        $this->assertSame(
            50.0,
            (float) $wageLines->where('account_id', $cashAccount->id)->sum('credit')
        );
        $this->assertSame(
            0,
            JournalEntry::where('reference', 'like', 'WAGE-'.$unassignedEmployee->id.'-%')->count()
        );
    }

    public function test_product_editors_can_assign_products_to_kitchen_stations(): void
    {
        $restaurant = $this->createRestaurant();
        $user = $this->createUser($restaurant);
        $this->grantPermissions($user, ['view_products', 'create_products']);
        $station = KitchenStation::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Grill',
            'display_name' => 'الشواء',
            'is_active' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/products/kitchen-stations')
            ->assertOk()
            ->assertJsonPath('stations.0.id', $station->id);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/products', [
                'name' => 'مشويات',
                'sale_price' => 100,
                'cost_price' => 50,
                'kitchen_station_id' => $station->id,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('products', [
            'id' => $response->json('data.id'),
            'kitchen_station_id' => $station->id,
        ]);
    }

    public function test_chef_user_requires_and_stores_a_kitchen_station(): void
    {
        $restaurant = $this->createRestaurant();
        $admin = $this->createUser($restaurant);
        $this->grantPermissions($admin, ['create_users']);
        Role::create(['name' => 'Chef', 'guard_name' => 'sanctum']);
        $station = KitchenStation::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Fry',
            'display_name' => 'المقلي',
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/users', [
                'name' => 'Chef User',
                'username' => 'chef.user',
                'password' => 'secret123',
                'role' => 'Chef',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('kitchen_station_id');

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/users', [
                'name' => 'Chef User',
                'username' => 'chef.user',
                'password' => 'secret123',
                'role' => 'Chef',
                'kitchen_station_id' => $station->id,
            ])
            ->assertCreated();

        $this->assertSame($station->id, $response->json('user.kitchen_station_id'));
    }

    public function test_station_kitchen_users_only_see_their_items_and_cannot_serve_unready_orders(): void
    {
        $restaurant = $this->createRestaurant();
        $chef = $this->createUser($restaurant);
        $this->grantPermissions($chef, ['view_station_kitchen_orders', 'update_kitchen_order']);
        $station = KitchenStation::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Grill',
            'display_name' => 'الشواء',
            'is_active' => true,
        ]);
        $otherStation = KitchenStation::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Drinks',
            'display_name' => 'المشروبات',
            'is_active' => true,
        ]);
        $chef->update(['kitchen_station_id' => $station->id]);

        $makeOrder = function (string $number, array $stations) use ($restaurant, $chef): Order {
            $order = Order::create([
                'restaurant_id' => $restaurant->id,
                'cashier_id' => $chef->id,
                'order_number' => $number,
                'type' => 'takeaway',
                'status' => 'preparing',
                'payment_status' => 'unpaid',
                'payment_method' => 'cash',
                'subtotal' => 100,
                'total_amount' => 100,
                'amount_paid' => 0,
            ]);
            foreach ($stations as $index => $station) {
                $product = Product::create([
                    'restaurant_id' => $restaurant->id,
                    'name' => $number.'-'.$index,
                    'slug' => Str::slug($number.'-'.$index),
                    'sale_price' => 100,
                    'cost_price' => 50,
                ]);
                $item = OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 100,
                    'total_price' => 100,
                    'kitchen_status' => 'preparing',
                ]);
                OrderKitchenStation::create([
                    'order_item_id' => $item->id,
                    'station_id' => $station->id,
                ]);
            }

            return $order;
        };
        $order = $makeOrder('STATION-ORDER-1', [$station, $otherStation]);
        $makeOrder('STATION-ORDER-2', [$otherStation]);
        $stationItem = $order->items()->firstOrFail();

        $this->actingAs($chef, 'sanctum')
            ->getJson('/api/kitchen/orders')
            ->assertOk()
            ->assertJsonCount(1, 'orders')
            ->assertJsonCount(1, 'orders.0.items')
            ->assertJsonPath('orders.0.can_mark_served', false);

        $this->actingAs($chef, 'sanctum')
            ->postJson('/api/kitchen/order-items/'.$stationItem->id.'/status', [
                'kitchen_status' => 'ready',
                'station_id' => $station->id,
            ])
            ->assertOk();
        $this->actingAs($chef, 'sanctum')
            ->postJson('/api/orders/'.$order->id.'/status', ['status' => 'served'])
            ->assertStatus(422);
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

    private function createPaidOrder(Restaurant $restaurant, User $cashier): Order
    {
        $order = Order::create([
            'restaurant_id' => $restaurant->id,
            'cashier_id' => $cashier->id,
            'order_number' => 'TEST-ORDER-'.$cashier->id.'-'.Str::random(5),
            'type' => 'takeaway',
            'status' => 'completed',
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'subtotal' => 250,
            'total_amount' => 250,
            'amount_paid' => 250,
        ]);
        Payment::create([
            'restaurant_id' => $restaurant->id,
            'paymentable_type' => Order::class,
            'paymentable_id' => $order->id,
            'amount' => 250,
            'method' => 'cash',
            'paid_at' => now(),
            'user_id' => $cashier->id,
        ]);

        return $order;
    }

    private function grantPermissions(User $user, array $permissions): void
    {
        $permissionModels = collect($permissions)->map(fn (string $permission) => Permission::firstOrCreate([
            'name' => $permission,
            'guard_name' => 'sanctum',
        ]));
        $role = Role::create(['name' => 'Test Role '.$user->id, 'guard_name' => 'sanctum']);
        $role->syncPermissions($permissionModels);
        $user->assignRole($role);
        $user->refresh();
        $this->assertTrue($role->hasPermissionTo($permissions[0]));
        $this->assertTrue($user->hasRole($role));
        $this->assertTrue($user->hasPermissionTo($permissions[0], 'sanctum'));
        foreach ($permissions as $permission) {
            $this->assertTrue($user->hasPermissionTo($permission, 'sanctum'), "Expected test user to have {$permission}.");
        }
    }
}
