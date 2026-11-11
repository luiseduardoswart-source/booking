# Booking Engine + PMS

Your own booking website for holiday rentals, with everything you need to run
them behind it. Guests find your place, see the price, book and pay directly on
your website, without a booking platform taking a commission. You manage
everything in one admin panel: properties, prices, calendar, payments, guest
emails and statistics.

Every installation is its own website with its own database. Name, colours,
texts and languages are all set from the admin panel, so it can be used for any
rental business.

---

## What it can do

### For your guests

- **A modern website** with a home page, search bar and a page per property with
  photos and videos, highlights, amenities, house rules, a map, reviews and
  the host(s). At the top there is always a photo; videos (mp4 or webm, up to
  100 MB) play between the photos and in the texts.
- **Search** by dates, number of guests and pets, with search suggestions as you
  type.
- **Live prices**: the guest sees the total and a full breakdown (nights,
  cleaning fee, tourist tax, discounts) while choosing dates. Optionally the
  nightly price is shown in every day of the calendar.
- **Book and pay** online through Mollie or Stripe: iDEAL, credit card,
  Bancontact, Apple Pay, Google Pay and more. Payment in full, or a down payment
  first and the rest later.
- **Coupon codes** at checkout.
- **Rental agreement** that the guest signs digitally when booking.
- **Identity check** of the guest through Stripe Identity (optional).
- **Reviews**: after their stay, guests get an invitation to write a review. You
  can reply to it.
- **Your language and currency**: Dutch, English, Spanish, French, German and
  Chinese out of the box, and more languages can be added. Prices are shown in
  the currency the guest picks.
- **AI chat assistant** (optional) that answers guest questions using your
  property details and your own documents.
- **Contact form**, share button, cookie banner (GDPR) and pages of your own
  (for example "About us" or "Things to do").

### Properties and prices

- **Defaults for all properties**, which each property can follow or override.
  Change something once and it applies everywhere.
- **Prices**: base price per night, weekend price (fixed amount or percentage),
  seasons, extra guest fee, cleaning fee, pet fee, tourist tax, and a currency
  per property.
- **Discounts** like on Airbnb: weekly and monthly (length of stay), last minute
  and early bird, as a percentage or a fixed amount. You choose whether discounts
  add up or only the best one applies.
- **Dynamic pricing** through PriceLabs, Beyond or Wheelhouse. Their nightly
  prices are fetched every day and take precedence over your own prices. Take a
  percentage or fixed amount off them on your own site (for example the
  surcharge that covers the Airbnb or Vrbo commission), and choose whether
  prices you set by hand in the calendar stay or get overwritten.
- **Booking rules**: minimum and maximum stay, how far ahead guests can book,
  arrival and departure days, and rules for specific periods.
- **Guest limits**: maximum guests, babies and pets per property.
- **Policies**: cancellation policy, house rules, check-in and check-out times,
  self check-in or a personal welcome by one of the hosts, security deposit.
- **Self check-in codes**: a fixed door code or the last four digits of the
  guest's phone number, plus own check-in details (WiFi password, parking code)
  as merge fields in the automatic emails. Stored encrypted, and only filled in
  for confirmed bookings.
- **Import a listing from a link** (for example your Airbnb page) to fill in a
  new property in seconds.
- **AI writing help** for descriptions, and **automatic translation** of your
  texts into the other languages with one click (DeepL).
- **Hosts** with photo and bio, more than one per property if you like.
- **SEO and social media** settings per property.

### Bookings, calendar and payments

- **Bookings overview** with statuses (new, confirmed, cancelled and so on),
  bookings you add yourself, and everything about the guest in one place.
- **Calendar sync** with Airbnb, Booking.com, Vrbo and any other platform that
  supports iCal, in both directions, every 30 minutes. Double bookings are
  flagged.
- **Block dates** yourself, for example for maintenance or personal use.
- **Payments**: payment links you can send, refunds from the admin panel, and
  invoices, booking confirmations and receipts as PDF.
- **Security deposit**: refunded automatically after departure, or a daily
  reminder for you to do it.

### Emails

- **Automatic guest emails**: booking confirmation, payment reminder, check-in
  instructions, check-out information and review request, in the guest's own
  language and at the time you choose.
- **Write your own texts** with tags such as the guest's name, the property and
  the dates, which are filled in automatically.
- **Notifications for you** by email: a new booking, a cancellation, a payment,
  or a conflict with an external calendar.
- **Your own mailbox**, set up in the admin panel, with a button to send a test
  email.

### Admin panel

- **Dashboard** with revenue, key figures and today's arrivals and departures.
- **Form builder**: add your own questions to the booking form and the contact
  form (the answers go with the booking and into the emails as merge fields),
  or build new forms such as a group request and place them on a page.
  Submissions are stored, emailed to the addresses you choose and marked as
  handled in the admin.
- **Statistics**: page views, views per property, conversion from visit to
  booking, most viewed photos and devices. Privacy-friendly, without extra
  cookies.
- **Media library** with folders, editing, alt texts and optional AI-generated
  images.
- **Style editor**: colours, fonts and layout, or import a ready-made design
  template.
- **Users and roles**: invite staff by email and decide per person what they can
  see and do. Two-factor sign-in (with an authenticator app) protects every
  account.
- **The admin panel speaks your language**, separate from the language of the
  website. Each user can choose their own.
- **Updates in one click**: after uploading a new version, a button on the
  dashboard updates the database.

### For developers

- **API** for properties, prices, availability and bookings (bookings require a
  token).

---

## Installing it on your website

No technical knowledge needed: you upload one file, open your website and fill
in a few screens. Everything happens in your browser.

The steps use **Hostinger** as an example. Other hosting providers work the same
way; only the names of the menus differ.

### What you need

- **Web hosting with PHP 8.4 or higher and a MySQL or MariaDB database.** Most
  "Premium" or "Business" hosting plans have this.
- **A domain name**, for example `yourdomain.com`, connected to that hosting,
  with the free **SSL certificate** switched on (the padlock in the browser; on
  Hostinger: **Security → SSL**).
- **A mailbox** for the website, for example `bookings@yourdomain.com` (on
  Hostinger: **Emails**). The website sends emails to guests and to you from
  this address.
- **Upload limits of at least 128 MB** if you want to add videos (up to 100 MB
  each): set `upload_max_filesize` and `post_max_size` to `128M` (on Hostinger:
  **Advanced → PHP Configuration → PHP options**). For photos only, 32 MB is
  enough.

### Step 1: Create a database

In your hosting panel, create a new database (on Hostinger: **Databases → MySQL
Databases**). Write down four things: the **database name**, the **user name**,
the **password** and the **host** (often `localhost`).

### Step 2: Upload the website

The zip file contains two folders:

- **`booking`**: the website itself, with your settings and passwords. Visitors
  can never reach this folder.
- **`public_html`**: only what visitors are allowed to see, such as images and
  styles.

You extract it **in the folder that contains `public_html`**, so both folders
land in the right place by themselves:

1. Download **`booking.zip`** from the
   [latest release](https://github.com/luiseduardoswart-source/booking/releases/latest).
2. In your hosting panel, open the **File Manager** and open the folder
   `public_html` of your domain. Delete everything in it (usually a sample
   page).
3. Go **one folder up**: the folder that contains `public_html`. On Hostinger
   that is `domains/yourdomain.com`.
4. Upload `booking.zip` there, right-click it and choose **Extract**. You now
   see a new folder `booking` next to `public_html`, and `public_html` is filled.
5. Delete `booking.zip`.

> Is your web folder not called `public_html` (for example `htdocs` or `www`)?
> Then move the contents of the extracted `public_html` folder into your web
> folder, or use the installation with a symlink below.

### Step 3: Run the installation wizard

Open your website, for example **`https://yourdomain.com`**. Use the address you
want visitors to see (with or without `www`): the website remembers it. The
installation wizard starts by itself:

1. **Installation code.** The wizard asks for a code. In the **File Manager**,
   open the file `booking/storage/app/install-code.txt`, copy the code and paste
   it in the wizard. This lock makes sure nobody else can install your website.
2. **Database.** The wizard first checks that your hosting has everything it
   needs. Then fill in the four things from step 1. The wizard sets up the
   database.
3. **Your account.** Choose your name, email address and a password of at least
   15 characters. The **Suggest** button makes a strong one for you.

You are now in the admin panel. The wizard switches itself off for good.

### Step 4: Finish the installation

The admin panel is available in several languages; the names below are the
English ones.

1. **Two-factor sign-in.** The admin panel asks you to connect an authenticator
   app on your phone (for example Google Authenticator or Microsoft
   Authenticator). Keep the recovery codes somewhere safe.
2. **The dashboard shows what is left**, under **Finish the installation**. It
   disappears when everything is done.
   - **Automatic tasks:** the dashboard shows one line, made for your website.
     In your hosting panel, add a cron job that runs **every minute** (on
     Hostinger: **Advanced → Cron Jobs**) and paste that line as the command.
     This sends the emails and keeps calendars and prices up to date.
   - **Mailbox:** go to **Settings → Email**, fill in the mailbox from "What you
     need" and click **Send test email**. You find the mail server details at
     your hosting provider (on Hostinger: `smtp.hostinger.com`, SSL).
3. **Settings → Company:** your company details.
4. **Settings → Keys:** choose your payment provider (Mollie or Stripe) and
   paste its key. Keys for translation, AI and dynamic pricing go here too.
5. **Accommodations:** set your defaults, then add your first accommodation, or
   import it from a link.

Done. Your website is live.

### Installing with SSH and a symlink (for experienced users)

If you prefer the command line, install from git instead of the zip file.
`public_html` then becomes a **symlink** (a shortcut) to the `public` folder of
the website, so the rest of the code is never reachable from the web. Updates
take one command (see below). On Hostinger, with your own domain:

```bash
cd ~/domains/yourdomain.com
git clone https://github.com/luiseduardoswart-source/booking.git app
cd app
composer2 install --no-dev --optimize-autoloader --no-scripts
php artisan package:discover
chmod -R 775 storage bootstrap/cache
cd ..
mv public_html public_html_old
ln -s app/public public_html
```

Check that `ls -la` shows `public_html -> app/public`. Then open your website and
follow steps 3 and 4; the installation code is in
`app/storage/app/install-code.txt`. On other hosting, use `composer` instead of
`composer2`.

> Does your hosting not allow symlinks (an error at `ln -s`)? Then use the zip
> file: it needs no symlink.

---

## Keeping it running

### Updating to a new version

- **Installed with SSH:** log in and run `./deploy.sh` in the `app` folder. It
  fetches the new version, updates the database and refreshes the caches.

  ```bash
  cd ~/domains/yourdomain.com/app && ./deploy.sh
  ```

- **Installed with the zip file:** extract the new `booking.zip` in the same
  place as before and let it overwrite the old files. Your `.env`, uploaded
  photos and documents are not in the zip, so they stay. Then open the admin
  panel: if the new version needs changes to the database, the dashboard shows
  **Update the website** with an **Update database** button. Make a backup
  first if your hosting provider does not do this automatically, then click the
  button.

Your bookings and settings stay as they are.

### Backups

These can never be replaced: the **database** (bookings, guests, payments), the
folder **`storage/app`** inside the website folder (uploaded
documents and the signed rental agreements), the folder **`storage`** in the web
folder (uploaded photos) and the file **`.env`** (your settings and passwords).
Most hosting plans make automatic backups; check this in your hosting panel (on
Hostinger: **Files → Backups**).

### Lost your phone and your recovery codes?

Someone with SSH access can switch off two-factor sign-in for your account, in
the website's folder (`booking` or `app`), with your email address:

```bash
php artisan admin:disable-two-factor you@example.com
```

At the next login you set it up again.

### Emails not arriving?

- Check **Settings → Email** and send a test email: an error message there
  usually says exactly what is wrong (address, password or port).
- Check that the cron job runs: as long as it doesn't, the dashboard keeps
  showing **Finish the installation**. Emails are sent by that task.

---

## For developers

Built with Laravel 13, Filament 5, Livewire 4 and Tailwind 4. Code, comments,
database columns and translation keys are in English. Interface texts are
translated through the files in `lang/` (`en.json` holds the source keys,
`nl.json` and the other files their translations).

### Local setup

You need PHP 8.4+ with Composer ([Laravel Herd](https://herd.laravel.com) works
well on Windows and Mac), Node.js with npm, and Docker for the local MariaDB.
Production runs MariaDB as well.

```bash
git clone https://github.com/luiseduardoswart-source/booking.git
```
```bash
cd booking && composer install && npm install && cp .env.example .env && php artisan key:generate
```

Point `.env` to the Docker database:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3307
DB_DATABASE=airbnb
DB_USERNAME=airbnb
DB_PASSWORD=choose-your-own-password
```

Start the database (it also creates the test database `airbnb_test`), create
the tables, and build the frontend. Run `view:cache` before the build, otherwise
Tailwind misses classes that only appear in compiled views:

```bash
docker compose up -d
```
```bash
php artisan migrate
```
```bash
php artisan view:cache && npm run build
```

Optional sample data:

```bash
php artisan db:seed --class=AmenitySeeder && php artisan db:seed --class=DemoPropertySeeder
```

Then open `/install/code` to create the first admin user (the code is in
`storage/app/install-code.txt`). To book without a real payment provider,
choose **Test (fake provider)** under **Settings → Keys → Payment provider**.

Without Herd, `composer dev` starts the server, queue worker, log viewer and
Vite together. `php artisan schedule:work` runs the scheduled tasks locally.

### Tests

Two suites: SQLite (fast) and MariaDB (same as production). Run them **one after
the other, never at the same time**, because they share the folder for fake
storage.

```bash
php artisan test
```
```bash
composer test:mysql
```

The MariaDB suite always uses `airbnb_test` and empties it on every run. Never
point it at the database of your local site.

### Deploying changes

The server has no Node, so the built frontend (`public/build`) is part of the
repository. After a frontend change, build locally (`view:cache`, then
`npm run build`) and commit `public/build` along with it. On the server,
`./deploy.sh` pulls the code, installs packages, runs migrations and refreshes
the caches.

**Releasing the zip file.** On GitHub, go to **Releases → Draft a new release**,
create a new tag (for example `v1.0.0`) and click **Publish release**. A GitHub
Actions workflow (`.github/workflows/release.yml`) then builds `booking.zip`
with the production packages (`vendor/`) and attaches it to the release. Files
marked `export-ignore` in `.gitattributes` (tests, Docker, PHPUnit config) are
left out.

How the zip installation works:

- The zip contains `booking/` (the app, without `public/`) and `public_html/`
  (the contents of `public/`). Extracted next to each other, the app sits
  outside the web root. `public/index.php` falls back to `../booking` when there
  is no `../vendor`, and `App\Support\DirectoryLayout` points Laravel's public path to
  the sibling `public_html` when the app has no `public/` folder.
- `.htaccess` in the project root denies every request, as a safety net in case
  the app folder ends up inside the web root after all.
- On the first request there is no `.env` yet. `App\Support\FirstStart`
  creates one from `.env.example` with a new `APP_KEY` and `APP_DEBUG=false`.
  The wizard then writes the database details, `APP_URL` and
  `APP_ENV=production`.
- After the wizard, a dashboard widget lists what is left (cron job and
  mailbox) until both work. The scheduler writes a heartbeat every minute.
- After an update, the same widget shows a button when there are pending
  migrations (`App\Support\DatabaseUpdater`). It runs `migrate --force` in the
  same PHP process, for administrators only.
- Mail settings from the admin panel (`App\Support\MailSettings`) override
  the `MAIL_` lines in `.env`. They are applied when the mailer is created, so
  they never end up in the `config:cache` file.

Shared hosting usually blocks `proc_open`, `exec` and `symlink`. That is why:

- scheduled tasks run inside the same PHP process (`routes/console.php`);
- Composer runs with `--no-scripts`, followed by `package:discover`;
- uploads are written straight to `public/storage`, so `storage:link` is not
  needed;
- there is no `route:cache`, because some routes are closures.

### Architecture

The business logic lives in dedicated classes, so the website, admin panel, API
and calendar sync all follow the same rules:

| Part | Where | Responsibility |
|---|---|---|
| Availability | `app/Engines/AvailabilityEngine.php` | Single source of truth for available nights |
| Pricing | `app/Engines/PricingEngine.php` | Price breakdown: nightly price, season, weekend, discounts, fees |
| Booking | `app/Engines/BookingEngine.php` | Creating bookings and their status transitions |
| Payment | `app/Engines/PaymentEngine.php`, `app/Payments/` | Mollie, Stripe and a test provider behind one interface |
| Calendar | `app/Engines/CalendarEngine.php` | iCal import and export |
| Documents | `app/Engines/DocumentEngine.php` | Invoices, confirmations and receipts as PDF |
| Notifications | `app/Engines/NotificationEngine.php` | Guest emails |
| Pricing services | `app/Services/*Provider.php` | PriceLabs, Beyond, Wheelhouse |
| Admin | `app/Filament/` | Filament screens |

Amounts are stored in cents. Everything is date-based: one night is one date.

### Scheduled tasks and commands

| Command | Schedule | What it does |
|---|---|---|
| `heartbeat` | every minute | Tells the dashboard that the cron job runs |
| `queue:work` | every minute | Sends the queued emails |
| `channels:sync` | every 30 minutes | Syncs iCal calendars |
| `prices:sync` | daily at 05:00 | Fetches dynamic prices |
| `mails:send-scheduled` | daily at 09:00 | Sends scheduled guest emails |
| `deposits:process` | daily at 09:30 | Refunds security deposits, or reminds the host |
| `lang:translate es` | by hand | Machine-translates a language file from English (DeepL) |
| `admin:disable-two-factor` | by hand | Disables two-factor sign-in for one account |
### API

`GET /api/v1/properties`, `/properties/{id}`, `/properties/{id}/quote` and
`/properties/{id}/unavailable-dates` are public. `/api/v1/bookings` requires a
Sanctum token.
