<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Offline - FundFlow</title>
    <meta name="theme-color" content="#059669">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #ecfdf5, #d1fae5);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #064e3b;
        }

        .container {
            text-align: center;
            padding: 2rem;
            max-width: 28rem;
        }

        .icon {
            width: 5rem;
            height: 5rem;
            margin: 0 auto 1.5rem;
            background: #059669;
            border-radius: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .icon svg {
            width: 2.5rem;
            height: 2.5rem;
            color: #fff;
        }

        h1 {
            font-size: 1.5rem;
            margin-bottom: 0.75rem;
        }

        p {
            color: #065f46;
            line-height: 1.6;
            margin-bottom: 1.5rem;
        }

        button {
            background: #059669;
            color: #fff;
            border: none;
            padding: 0.75rem 2rem;
            border-radius: 0.5rem;
            font-size: 1rem;
            cursor: pointer;
            transition: background 0.2s;
        }

        button:hover {
            background: #047857;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="icon">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round"
                    d="M8.288 15.038a5.25 5.25 0 0 1 7.424 0M5.106 11.856c3.807-3.808 9.98-3.808 13.788 0M1.924 8.674c5.565-5.565 14.587-5.565 20.152 0M12.53 18.22l-.53.53-.53-.53a.75.75 0 0 1 1.06 0Z" />
            </svg>
        </div>
        <h1>You're Offline</h1>
        <p>It looks like you've lost your internet connection. Please check your network and try again.</p>
        <button onclick="window.location.reload()">Try Again</button>
    </div>
</body>

</html>