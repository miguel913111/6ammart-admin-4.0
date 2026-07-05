<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ translate('messages.payment_account_onboarding') }} - {{ Helpers::get_business_settings('business_name') ?? config('app.name') }}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            color: #333;
        }
        .card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
            padding: 40px;
            max-width: 480px;
            text-align: center;
        }
        .icon {
            width: 64px;
            height: 64px;
            background: #e6f4ea;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
        }
        .icon svg {
            width: 32px;
            height: 32px;
            stroke: #1e8e3e;
        }
        h1 {
            font-size: 22px;
            margin: 0 0 12px;
        }
        p {
            font-size: 15px;
            line-height: 1.5;
            margin: 0 0 24px;
            color: #666;
        }
        a.button {
            display: inline-block;
            background: #0d6efd;
            color: #fff;
            text-decoration: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 500;
        }
        a.button:hover {
            background: #0b5ed7;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
                <path d="M20 6L9 17l-5-5"/>
            </svg>
        </div>
        <h1>{{ translate('messages.onboarding_complete') }}</h1>
        <p>{{ translate('messages.your_payment_account_has_been_submitted_for_review') }}</p>
        <a href="{{ url('/') }}" class="button">{{ translate('messages.go_to_home') }}</a>
    </div>
</body>
</html>
