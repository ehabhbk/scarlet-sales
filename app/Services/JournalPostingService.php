<?php

namespace App\Services;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JournalPostingService
{
    public function assertConfiguredPaymentMethod(int $restaurantId, string $method): Account
    {
        $settings = Setting::where('restaurant_id', $restaurantId)
            ->where('group', 'pos')
            ->where('key', 'payment_methods')
            ->value('value');
        if ($settings === null) {
            throw ValidationException::withMessages([
                'payment_method' => ['لم تُضبط طرق الدفع للمطعم؛ أضف طريقة دفع واربطها بحساب نشط من الإعدادات.'],
            ]);
        }

        return $this->paymentAccount($restaurantId, $method);
    }

    public function paymentAccount(int $restaurantId, string $method): Account
    {
        $settings = Setting::where('restaurant_id', $restaurantId)
            ->where('group', 'pos')
            ->where('key', 'payment_methods')
            ->value('value');
        if ($settings === null) {
            throw ValidationException::withMessages([
                'payment_method' => ['لم تُضبط طرق الدفع للمطعم؛ أضف طريقة دفع واربطها بحساب نشط من الإعدادات.'],
            ]);
        }

        $methods = $settings !== null ? json_decode($settings, true) : null;
        $accountId = null;
        $matchedMethod = false;
        $methodKey = mb_strtolower(trim($method));

        if ($settings !== null && ! is_array($methods)) {
            throw ValidationException::withMessages([
                'payment_method' => ['إعدادات طرق الدفع غير صالحة؛ يرجى مراجعتها قبل الترحيل.'],
            ]);
        }

        if (is_array($methods)) {
            foreach ($methods as $paymentMethod) {
                if (! is_array($paymentMethod)) {
                    continue;
                }

                foreach ([$paymentMethod['value'] ?? null, $paymentMethod['method'] ?? null] as $configuredMethod) {
                    if (is_string($configuredMethod) && mb_strtolower(trim($configuredMethod)) === $methodKey) {
                        $matchedMethod = true;
                        $accountId = isset($paymentMethod['account_id']) ? (int) $paymentMethod['account_id'] : null;
                        break 2;
                    }
                }
            }
        }

        if ($matchedMethod && ! $accountId) {
            throw ValidationException::withMessages([
                'payment_method' => ['طريقة الدفع المحددة غير مرتبطة بحساب؛ اربطها بحساب من الإعدادات.'],
            ]);
        }

        if ($settings !== null && ! $matchedMethod) {
            throw ValidationException::withMessages([
                'payment_method' => ['طريقة الدفع المحددة غير موجودة في إعدادات المطعم أو غير مرتبطة بحساب.'],
            ]);
        }

        if ($accountId) {
            $account = Account::where('restaurant_id', $restaurantId)
                ->whereKey($accountId)
                ->where('is_active', true)
                ->first();

            if (! $account) {
                throw ValidationException::withMessages([
                    'payment_method' => ['حساب طريقة الدفع غير صالح أو غير نشط للمطعم الحالي.'],
                ]);
            }

            return $account;
        }

        throw ValidationException::withMessages([
            'payment_method' => ['طريقة الدفع المحددة غير موجودة في إعدادات المطعم أو غير مرتبطة بحساب.'],
        ]);
    }

    /**
     * @param  array<int, array{account_id:int, debit?:float, credit?:float, description?:?string}>  $lines
     */
    public function post(
        int $restaurantId,
        string $numberPrefix,
        string $reference,
        string $date,
        string $description,
        ?int $userId,
        array $lines
    ): JournalEntry {
        $existing = JournalEntry::where('restaurant_id', $restaurantId)
            ->where('reference', $reference)
            ->first();
        if ($existing) {
            return $existing->load('lines.account');
        }

        $debit = 0;
        $credit = 0;
        foreach ($lines as $line) {
            $account = Account::where('restaurant_id', $restaurantId)
                ->whereKey($line['account_id'])
                ->where('is_active', true)
                ->first();
            if (! $account) {
                throw ValidationException::withMessages([
                    'account_id' => ['أحد الحسابات المرتبطة بالقيد غير صالح أو غير نشط.'],
                ]);
            }

            $lineDebit = round((float) ($line['debit'] ?? 0), 2);
            $lineCredit = round((float) ($line['credit'] ?? 0), 2);
            if ($lineDebit < 0 || $lineCredit < 0 || ($lineDebit > 0 && $lineCredit > 0)) {
                throw ValidationException::withMessages([
                    'lines' => ['يجب أن يحتوي كل سطر على مبلغ مدين أو دائن غير سالب، وليس كليهما.'],
                ]);
            }
            $debit += $lineDebit;
            $credit += $lineCredit;
        }

        if (count($lines) < 2 || abs(round($debit - $credit, 2)) > 0.001 || $debit <= 0) {
            throw ValidationException::withMessages([
                'lines' => ['القيد غير متوازن أو لا يحتوي على سطرين على الأقل.'],
            ]);
        }

        return DB::transaction(function () use ($restaurantId, $reference, $date, $description, $userId, $lines) {
            DB::table('restaurants')->where('id', $restaurantId)->lockForUpdate()->first();

            $entry = JournalEntry::create([
                'restaurant_id' => $restaurantId,
                'entry_number' => JournalEntry::nextEntryNumber($restaurantId),
                'entry_date' => $date,
                'reference' => $reference,
                'description' => $description,
                'created_by' => $userId,
            ]);

            foreach ($lines as $line) {
                $entry->lines()->create([
                    'account_id' => $line['account_id'],
                    'debit' => round((float) ($line['debit'] ?? 0), 2),
                    'credit' => round((float) ($line['credit'] ?? 0), 2),
                    'description' => $line['description'] ?? null,
                ]);
            }

            return $entry->load('lines.account');
        });
    }
}
