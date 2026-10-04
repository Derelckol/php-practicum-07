# PHP Practicum 06 — API

## Варіант 15

Система бронювання квитків.

Ресурс:

`events`

Поля:

- `id`
- `title`
- `event_date`
- `venue`
- `seats_left`

---

## Отримати всі події

### Request

GET

/api.php?resource=events

### Response

HTTP 200 OK

```json
{
    "success": true,
    "data": []
}