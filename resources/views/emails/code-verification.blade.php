<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Votre code KoudMain</title>
</head>
<body style="margin:0;padding:0;background:#f4f2ed;">
    {{-- Mêmes styles écrits en dur que emails/notification (les messageries n'acceptent pas de feuille de style). --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f2ed;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;">
                    <tr>
                        <td style="padding:0 4px 18px;font:600 20px/1 Arial,Helvetica,sans-serif;color:#1c1b17;letter-spacing:-0.5px;">
                            KoudMain<span style="color:#b25f38;">.</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#ffffff;border:1px solid #e3ded2;border-radius:16px;padding:28px 28px 30px;font:400 15px/1.6 Arial,Helvetica,sans-serif;color:#3d3b34;">
                            <p style="margin:0 0 6px;font-size:14px;color:#6b675c;">Bonjour {{ $prenom }},</p>
                            <h1 style="margin:0 0 12px;font:600 21px/1.3 Arial,Helvetica,sans-serif;color:#1c1b17;">Votre code de vérification</h1>
                            <p style="margin:0 0 18px;">Saisissez ce code dans l'application KoudMain pour {{ $pourquoi }}.</p>
                            <p style="margin:0 0 18px;font:700 34px/1 'Courier New',Courier,monospace;letter-spacing:8px;color:#1c1b17;background:#f5f2ec;border-radius:12px;padding:18px 0;text-align:center;">{{ $code }}</p>
                            <p style="margin:0;font-size:14px;color:#6b675c;">Ce code est valable {{ $minutes }} minutes et ne sert qu'une fois. Ne le communiquez à personne : l'équipe KoudMain ne vous le demandera jamais.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 8px 0;font:400 12px/1.6 Arial,Helvetica,sans-serif;color:#6b675c;">
                            Vous n'avez rien demandé ? Ignorez simplement cet e-mail : sans ce code, personne ne peut utiliser votre compte.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
