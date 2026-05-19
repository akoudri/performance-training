<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Ticket Resonance — {{ $event->title }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #222; }
        .ticket { border: 2px solid #111; padding: 24px; max-width: 600px; }
        .label { font-size: 11px; color: #666; text-transform: uppercase; }
        .value { font-size: 16px; margin-bottom: 12px; }
        .header { font-size: 24px; font-weight: bold; margin-bottom: 16px; }
        .code { font-family: monospace; font-size: 14px; padding: 8px; background: #f4f4f4; }
    </style>
</head>
<body>
    <div class="ticket">
        <div class="header">{{ $event->title }}</div>

        <div class="label">Porteur</div>
        <div class="value">{{ $ticket->holder_name }}</div>

        <div class="label">Catégorie</div>
        <div class="value">{{ $category->name }}</div>

        <div class="label">Session</div>
        <div class="value">
            {{ \Carbon\Carbon::parse($session->starts_at)->translatedFormat('l j F Y \à H\hi') }}
        </div>

        <div class="label">Lieu</div>
        <div class="value">{{ $event->venue_name }}, {{ $event->city }}</div>

        <div class="label">Code ticket (présenter à l'entrée)</div>
        <div class="code">{{ $ticket->code }}</div>

        <p style="margin-top: 32px; font-size: 11px; color: #888;">
            Commande #{{ $order->id }} — Resonance.
        </p>
    </div>
</body>
</html>
