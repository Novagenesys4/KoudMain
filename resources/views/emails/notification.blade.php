<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $titre }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f2ed;">
    {{-- Les e-mails n'acceptent ni feuille de style externe ni variables CSS : couleurs et styles sont donc écrits en dur, ici seulement. --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f2ed;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;">
                    <tr>
                        <td style="padding:0 4px 18px;font:600 20px/1 Arial,Helvetica,sans-serif;color:#1c1b17;letter-spacing:-0.5px;">
                            KoudMain<span style="color:#8a4b1a;">.</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#ffffff;border:1px solid #e3ded2;border-radius:16px;padding:28px 28px 30px;font:400 15px/1.6 Arial,Helvetica,sans-serif;color:#3d3b34;">
                            <p style="margin:0 0 6px;font-size:14px;color:#6b675c;">Bonjour {{ $prenom }},</p>
                            <h1 style="margin:0 0 12px;font:600 21px/1.3 Arial,Helvetica,sans-serif;color:#1c1b17;">{{ $titre }}</h1>
                            <p style="margin:0 0 24px;">{{ $texte }}</p>
                            <a href="{{ $lien }}" style="display:inline-block;background:#1c1b17;color:#ffffff;text-decoration:none;font:600 15px/1 Arial,Helvetica,sans-serif;padding:13px 22px;border-radius:12px;">{{ $bouton }}</a>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 8px 0;font:400 12px/1.6 Arial,Helvetica,sans-serif;color:#6b675c;">
                            Vous recevez cet e-mail parce que vous avez un compte KoudMain. Pour ne plus en recevoir, décochez « Recevoir les notifications par e-mail » dans <a href="{{ url('/compte/profil') }}" style="color:#6b675c;">Mon profil</a> : vous continuerez à voir vos notifications sur le site.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
