<?php
session_start();
if (!isset($_SESSION["UserName"]) || !isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MINOA - Ana Sayfa</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            overflow: hidden;
        }

        /* Navbar solda */
        .navbar-container {
            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;
            z-index: 1000;
        }

        /* Ana içerik - ortada MINOA */
        .main-content {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-left: 70px; /* Navbar genişliği */
            min-height: 100vh;
        }

        .minoa-logo-container {
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .minoa-logo-svg {
            width: 800px;
            height: 200px;
        }

        .minoa-logo-text {
            font-size: 180px;
            font-family: 'Dancing Script', cursive;
            font-weight: 700;
            fill: transparent;
            stroke: #3b82f6;
            stroke-width: 4;
            stroke-dasharray: 2000;
            stroke-dashoffset: 2000;
            animation: minoaDraw 8s ease-in-out forwards;
            text-anchor: middle;
            dominant-baseline: middle;
        }

        @keyframes minoaDraw {
            to {
                stroke-dashoffset: 0;
                fill: #3b82f6;
            }
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .minoa-logo-svg {
                width: 600px;
                height: 150px;
            }

            .minoa-logo-text {
                font-size: 140px;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }

            .minoa-logo-svg {
                width: 400px;
                height: 100px;
            }

            .minoa-logo-text {
                font-size: 90px;
                stroke-width: 3;
            }
        }

        @media (max-width: 480px) {
            .minoa-logo-svg {
                width: 300px;
                height: 80px;
            }

            .minoa-logo-text {
                font-size: 70px;
                stroke-width: 2.5;
            }
        }
    </style>
</head>
<body>
    <div class="navbar-container">
        <?php include 'navbar.php'; ?>
    </div>
    
    <div class="main-content">
        <div class="minoa-logo-container">
            <svg class="minoa-logo-svg" viewBox="0 0 800 200" preserveAspectRatio="xMidYMid meet">
                <text x="50%" y="50%" class="minoa-logo-text">MINOA</text>
            </svg>
        </div>
    </div>

      
      <script>
        window.addEventListener('load', function() {
            setTimeout(startConfetti, 2000); // MINOA animasyonu bittikten sonra başlat
        });

        function startCnfetti() {
            const canvas = document.createElement('canvas');
            canvas.id = 'confetti-canvas';
            canvas.style.position = 'fixed';
            canvas.style.top = '0';
            canvas.style.left = '0';
            canvas.style.width = '100%';
            canvas.style.height = '100%';
            canvas.style.pointerEvents = 'none';
            canvas.style.zIndex = '9999';
            document.body.appendChild(canvas);
            
            const ctx = canvas.getContext('2d');
            canvas.width = window.innerWidth;
            canvas.height = window.innerHeight;
            
            const colors = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#06b6d4', '#84cc16'];
            const confetti = [];
            const confettiCount = 200;
            
            for (let i = 0; i < confettiCount; i++) {
                confetti.push({
                    x: Math.random() * canvas.width,
                    y: -Math.random() * canvas.height,
                    r: Math.random() * 6 + 4,
                    d: Math.random() * confettiCount,
                    color: colors[Math.floor(Math.random() * colors.length)],
                    tilt: Math.floor(Math.random() * 10) - 10,
                    tiltAngleIncrement: Math.random() * 0.07 + 0.05,
                    tiltAngle: 0
                });
            }
            
            let animationId;
            function animate() {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                
                confetti.forEach((c, i) => {
                    ctx.beginPath();
                    ctx.lineWidth = c.r / 2;
                    ctx.strokeStyle = c.color;
                    ctx.moveTo(c.x + c.tilt + c.r, c.y);
                    ctx.lineTo(c.x + c.tilt, c.y + c.tilt + c.r);
                    ctx.stroke();
                    
                    c.tiltAngle += c.tiltAngleIncrement;
                    c.y += (Math.cos(c.d) + 3 + c.r / 2) / 2;
                    c.tilt = Math.sin(c.tiltAngle - i / 3) * 15;
                    
                    if (c.y > canvas.height) {
                        confetti[i] = {
                            x: Math.random() * canvas.width,
                            y: -20,
                            r: c.r,
                            d: c.d,
                            color: c.color,
                            tilt: Math.floor(Math.random() * 10) - 10,
                            tiltAngleIncrement: c.tiltAngleIncrement,
                            tiltAngle: c.tiltAngle
                        };
                    }
                });
                
                animationId = requestAnimationFrame(animate);
            }
            
            animate();
        }
    </script>
</body>
</html>


