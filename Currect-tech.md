**Frontend (root, static site)**

* Vite 8 — build tool
* Tailwind CSS 4 (`@tailwindcss/vite` plugin)
* Vanilla JS (`src/app.js`, `src/app.css`)
* Plain HTML pages (index, product, cart, checkout, account, wishlist, search, blog, 404)
* Note: comment in [vite.config.js](vscode-webview://1boat6i8jccbuqfjb0adot4aq56hknumi97gb5i48ffsti4j09te/vite.config.js) says setup mirrors Laravel+Vite intentionally — future merge planned

**Backend (Laravel, PHP 8.3+)**

* Laravel Framework ^13.8
* **Filament 5.7** — admin panel
* Filament Shield — role/permission UI for Filament
* **Spatie Laravel Permission** — roles/permissions
* **Spatie Media Library** (+ Filament plugin) — file/image uploads
* **Laravel Scout + Meilisearch** — product search
* **Intervention Image** — image processing
* **Laravel DomPDF** — PDF generation (invoices?)
* **Predis** — Redis client
* **Sentry Laravel** — error monitoring
* Laravel Tinker, Pail (logs), Pint (formatter) — dev tools
* Pest/PHPUnit — testing

**Infra/DB**

* DB: SQLite default (dev), swappable to MySQL
* Redis — cache/queue/session likely
* Session driver: database
* Docker (Dockerfile + docker-compose.yml)
* Queue worker configured (`queue:listen` in dev script)

**project run**

PHP+composer install phir se start hua background me (pid 25924). Time lagega.

Ab dono chalane ka tarika:

**Frontend** (already ready, koi setup nahi chahiye):

```
cd /Users/ayushman/Desktop/estele-jewellery
npm run dev
```

→ [http://localhost:5173](http://localhost:5173/)

**Backend** (Laravel, php/composer install hone ke baad):

```
cd /Users/ayushman/Desktop/estele-jewellery/backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

→ [http://localhost:8000](http://localhost:8000/), admin panel [http://localhost:8000/admin](http://localhost:8000/admin)

Backend ke apna vite bhi hai styling ke liye:

```
cd backend
npm install
npm run dev
```

Install complete hote hi khud continue kar dunga backend setu

**PROJECT RUN CMD**

**Frontend:**

```
cd /Users/ayushman/Desktop/estele-jewellery
npm run dev
```

→ [http://localhost:5173](http://localhost:5173/)

**Backend:**

```
cd /Users/ayushman/Desktop/estele-jewellery/backend
php artisan serve --port=8000
```

→ [http://127.0.0.1:8000](http://127.0.0.1:8000/)
→ Admin: [http://127.0.0.1:8000/admin/login](http://127.0.0.1:8000/admin/login) (email: `admin@estele.local`, password: `password`)

Dono terminal chalte rehne chahiye (Ctrl+C se band karo). `php`/`composer` already `/usr/local/bin` me symlinked hain, koi extra setup nahi lagega ab.

**Backend:** [http://127.0.0.1:8000](http://127.0.0.1:8000/)
**Admin panel:** [http://127.0.0.1:8000/admin/login](http://127.0.0.1:8000/admin/login)

**Login:**

* Email: `admin@estele.local`
* Password: `password`

**Frontend:** [http://localhost:5173](http://localhost:5173/)

PHP install method: brew fail ho raha tha (macOS 12 unsupported libpq build), to static PHP binary use kiya (`/Users/ayushman/php-static/php`, symlinked `/usr/local/bin/php`) — koi system password/sudo nahi lagi. Password change wapas kar lena agar zaroori na ho.
