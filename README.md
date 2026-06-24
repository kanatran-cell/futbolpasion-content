# futbolpasion-content — Puente de contenido

Repositorio puente del motor de noticias de **futbolpasion.cl** (mecanismo **sin API**).

## Flujo
1. **Agente Claude (nube, gratis)** corre cada 30 min: investiga el fútbol chileno (Google News RSS, La Tercera, ESPN.cl), redacta **notas originales** en es-CL y deja un archivo `.json` por nota en **`pending/`**, luego hace commit/push (push nativo de la plataforma).
2. **Cron en cPanel (servidor de futbolpasion.cl)** corre cada 30 min: hace `git pull`, publica en WordPress **todas** las notas nuevas de `pending/` (`wp_insert_post`), y las mueve a `published/`.

No se usa ninguna API de LLM: el agente (suscripción) redacta; el servidor solo publica.

## Carpetas
- `pending/` — notas listas para publicar (una por archivo `.json`).
- `published/` — notas ya publicadas (las mueve el publicador del servidor).

## Formato de cada nota (`pending/AAAA-MM-DDTHHMM-slug.json`)
```json
{
  "title": "Titular específico y atractivo (sin clickbait)",
  "excerpt": "Bajada de 1-2 frases.",
  "content_html": "<p>3 a 5 párrafos en HTML. Último párrafo: <em>Fuente: NOMBRE — <a href=\"URL\" target=\"_blank\" rel=\"noopener nofollow\">dominio</a></em></p>",
  "category_slug": "primera-division | copa-chile | ascenso | la-roja | mercado | opinion",
  "source_name": "RedGol",
  "source_url": "https://redgol.cl/...",
  "created_utc": "2026-06-24T19:30:00Z"
}
```
