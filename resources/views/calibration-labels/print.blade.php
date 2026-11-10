<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Calibration Label</title>
    <style>
        @page { size: 70mm 40mm; margin: 0; }
        html, body { height: 40mm; margin: 0; width: 70mm; }
        body { align-items: center; display: flex; justify-content: center; }
        img { display: block; height: 40mm; width: 70mm; }
        @media print { body { height: 40mm; width: 70mm; } }
    </style>
</head>
<body onload="window.print()">
    <img src="{{ $previewUrl }}" alt="Calibration Label">
</body>
</html>
