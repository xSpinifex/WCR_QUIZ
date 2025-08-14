# WCR Quiz Plugin

Prosta wtyczka WordPress umożliwiająca rejestrację uczniów oraz przeprowadzenie zaplanowanego quizu z pytaniami zamkniętymi.

## Instalacja
1. Skopiuj katalog `wcr-quiz` do folderu `wp-content/plugins`.
2. W panelu WordPress aktywuj wtyczkę **WCR Quiz**.

## Konfiguracja
W panelu administracyjnym w sekcji **Ustawienia → WCR Quiz** ustaw:
- datę i godzinę startu,
- czas trwania w minutach,
- pytania w formacie JSON, np.
```json
[
  {"question":"2+2?","answers":["3","4","5","6"],"correct":1}
]
```
- czy wynik ma być pokazany uczestnikowi.

## Shortcody
- `[wcr_registration]` – formularz rejestracji (imię, e‑mail, login i hasło).
- `[wcr_quiz]` – logowanie i udział w quizie.

Wersje testowane: WordPress 5.7.2, PHP 7.4.33.
