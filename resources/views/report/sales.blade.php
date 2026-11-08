<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تقرير المبيعات</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, sans-serif; direction: rtl; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: right; }
        th { background: #f4f4f4; }
        .title { text-align: center; font-size: 18px; margin-bottom: 16px; font-weight: bold; }
        .meta { text-align: center; margin-bottom: 16px; font-size: 13px; color: #555; }
        .total-row { font-weight: bold; background: #eef; }
    </style>
</head>
<body>
    <div class="title">تقرير المبيعات - scarlet restaurant</div>
    <div class="meta">من {{ $from }} إلى {{ $to }}</div>
    <table>
        <thead>
        <tr>
            <th>رقم الطلب</th><th>التاريخ</th><th>الكاشير</th><th>المبلغ</th><th>طريقة الدفع</th><th>الحالة</th>
        </tr>
        </thead>
        <tbody>
        @foreach($orders as $o)
        <tr>
            <td>{{ $o['order_number'] }}</td>
            <td>{{ $o['date'] }}</td>
            <td>{{ $o['cashier'] }}</td>
            <td>{{ number_format($o['total'], 3) }} ر.س</td>
            <td>{{ $o['method'] }}</td>
            <td>{{ $o['status'] }}</td>
        </tr>
        @endforeach
        <tr class="total-row">
            <td colspan="3">الإجمالي</td>
            <td>{{ number_format($orders->sum('total'), 3) }} ر.س</td>
            <td colspan="2"></td>
        </tr>
        </tbody>
    </table>
</body>
</html>
