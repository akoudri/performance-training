<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Confirmation de commande</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #222; max-width: 600px; margin: 24px auto;">
    <h1>Merci pour votre commande !</h1>

    <p>Votre commande <strong>#{{ $order->id }}</strong> a bien été enregistrée et payée.</p>

    <h2>Récapitulatif</h2>
    <ul>
        @foreach ($tickets as $ticket)
            <li>
                Ticket <code>{{ substr($ticket->code, 0, 8) }}</code> —
                {{ $ticket->holder_name }}
            </li>
        @endforeach
    </ul>

    <p><strong>Total :</strong> {{ number_format($order->total_cents / 100, 2, ',', ' ') }} €</p>

    <p>Vos billets en PDF sont disponibles dans votre espace personnel.</p>

    <p style="font-size: 12px; color: #888; margin-top: 32px;">
        Resonance — confirmation envoyée le {{ $order->updated_at?->format('d/m/Y à H:i') }}.
    </p>
</body>
</html>
