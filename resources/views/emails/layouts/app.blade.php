<!DOCTYPE html>
<html lang="en" style="margin:0; padding:0;">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap');

        body {
            font-family: 'Poppins', sans-serif;
            margin: 0;
            padding: 0;
            background-color: #fff1f5;
            color: #111827;
        }

        .container {
            max-width: 600px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 5px 30px rgba(0, 0, 0, 0.05);
        }

        .header {
            background: linear-gradient(to right, #ec4899, #8b5cf6);
            color: white;
            padding: 24px;
            text-align: center;
            font-size: 24px;
            font-weight: 700;
        }

        .content {
            padding: 32px;
        }

        .button {
            display: inline-block;
            background: linear-gradient(to right, #ec4899, #8b5cf6);
            color: white !important;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 12px;
            font-weight: 600;
            margin-top: 16px;
        }

        .footer {
            background: #f9fafb;
            padding: 24px;
            text-align: center;
            font-size: 12px;
            color: #6b7280;
        }

        .social-icons img {
            width: 20px;
            margin: 0 6px;
            opacity: 0.6;
        }

        a {
            color: #6366f1;
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }
    </style>
    <title>Email</title>
</head>
<body>
<table width="100%" height="100%" cellpadding="0" cellspacing="0" border="0" style="height:100vh;">
    <tr>
        <td align="center" valign="middle">
            <div class="container">
                <div class="header">
                    Celebrate It With Me
                </div>
                <div class="content">
                    @yield('content')
                </div>
                <div class="footer">
                    Sent from <a href="https://celebrateitwithme.com">CelebrateItWithMe.com</a><br>
                    If you have questions, email us at <a href="mailto:hello@celebrateitwithme.com">hello@celebrateitwithme.com</a>
                    <div class="social-icons" style="margin-top: 12px;">
                        <a href="#"><img src="https://cdn-icons-png.flaticon.com/512/733/733547.png" alt="Facebook"></a>
                        <a href="#"><img src="https://cdn-icons-png.flaticon.com/512/733/733558.png" alt="Twitter"></a>
                        <a href="#"><img src="https://cdn-icons-png.flaticon.com/512/2111/2111463.png" alt="Instagram"></a>
                    </div>
                </div>
            </div>
        </td>
    </tr>
</table>
</body>
</html>
