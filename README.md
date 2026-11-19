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
- **Identity check** of the guest through Stripe Identity (optional): before
  the payment, or after it with a deadline before arrival. A guest who has not
  done it yet gets a reminder; when the deadline passes you choose whether the
  booking is cancelled (with the refund you pick) or you get a notification.
  The check-in email waits until the check is done.
- **My bookings**: through the icon at the top right, guests see their bookings
  and download the confirmation, invoice and receipts, without a password. They
  get a sign-in link by email (valid for 15 minutes); the browser then stays
  signed in for 90 days. Every guest email also has a direct link to the
  booking.
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
  seasons, extra guest fee, cleaning fee, pet fee, tourist tax (per person per
  night, per night or once per stay), and a currency per property.
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
  language and at the time you choose. The booking confirmation goes out once
  the payment has succeeded, with the confirmation and receipt attached.
- **Your logo and company name** at the top of every email.
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
- **Work from an AI app**: connect Claude, ChatGPT or another AI app (MCP)
  and ask in plain language for your bookings, change prices, texts and the
  calendar, or have the look of the website adjusted. Off until you switch it
  on; see [Working with an AI app](#working-with-an-ai-app-mcp).

### For developers

- **API** for properties, prices, availability and bookings (bookings require a
  token).
- **MCP server** for AI apps, with OAuth sign-in through the admin (see
  [Working with an AI app](#working-with-an-ai-app-mcp)).

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

Your bookings and settings stay as they are. After an update with the zip
file, the website clears its old pages and caches once by itself.

### Before going live: remove the test bookings

Tested by making bookings on the live website? Remove them before real guests
arrive, so the first real booking and invoice get number 0001. Log in with SSH
and run, in the website's folder (`booking` or `app`):

```bash
php artisan bookings:clear-test-data
```

It first shows what it will remove and asks for confirmation:

- removed: all bookings, with their payments, invoices, sent emails, reviews,
  guest sign-in links, log lines, queued emails and signatures;
- reset: the booking and invoice numbering starts at 0001 again, and coupon
  usage at 0;
- kept: properties, prices, settings, pages, users and blocked dates.

It refuses when a real payment (made with a live Mollie or Stripe key) is among
them. Payments made before the website remembered this are listed, and it asks
whether they were all test payments. A real booking that has not been paid yet
cannot be told apart from a test booking, so run it just before going live, not
after.

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

## Working with an AI app (MCP)

You can manage the website from an AI app such as Claude or ChatGPT instead of
clicking through the admin: "Which guests arrive this week?", "Set the cleaning
fee of the beach house to 45 euros", "Block 10 to 12 June for painting", "Make
the buttons dark green and the headings larger". The website offers this as an
MCP server, the open standard these apps use to connect to other software. The
app uses the website's own rules, so a price it sets is the price a guest gets.

### Switching it on

1. In the admin go to **Settings → AI connection** and switch on **AI
   connection on**. The address for the app appears, for example
   `https://your-domain.com/mcp`.
2. Under **Apps that may connect**, keep the apps you use: Claude, ChatGPT,
   VS Code, Cursor and apps on your own computer (Claude Code, Gemini CLI,
   Codex and others). Another app can be added by the https address it sends
   you back to after logging in. Only these apps can register.
3. In your AI app, add a custom connector (also called an MCP server) and paste
   the address. In Claude: **Settings → Connectors → Add custom connector**.
   With Claude Code: `claude mcp add --transport http booking
   https://your-domain.com/mcp`, then `/mcp` to log in.
4. Click **Connect**. Your own admin opens: log in (with your two-factor code if
   you use one) and click **Allow**. Then ask, for example: "Show my
   accommodations".

The app reaches your website over the internet, so the address must be your
live domain with https; a local test site such as `airbnb.test` only works with
an app on the same computer.

### What the app can and cannot do

Everyone connects with their own login and gets exactly the rights they have in
the admin: the owner everything below, a host only their own accommodations,
a staff member only the screens the owner gave them.

- **Read**: accommodations with their texts and prices, the calendar, a price
  calculation for any dates, bookings (one booking with the guest's contact
  details), statistics and the content pages.
- **Change**: the name, short description, description and house rules per
  language; prices (base price, weekend adjustment, cleaning fee, tourist tax
  and how it is charged, guests included, extra guest and pet fee); blocking and
  freeing nights; the title and text of content pages.
- **The look (owner only)**: a style template, the colours, header, font and
  buttons, the page layout, and style rules per element like the style editor.
  Before every change the whole look is remembered: ask the app to undo it, or
  click **Undo the last change made through the AI connection** under
  **Settings → Layout & style**. The last 20 changes can be undone.
- **Not possible**: payments and refunds, deleting accommodations or bookings,
  Settings and users, installing layout templates and your own scripts. Those
  stay in the admin. Texts that contain photos or videos are also left to the
  admin, so nothing gets lost.

Every change is in the activity log under the name of the person who connected.

### Disconnecting

**Settings → AI connection** shows who is connected with which app.
**Disconnect everyone** ends all connections; switching the connection off does
the same, and the address then no longer exists. An app you remove from the
list can no longer log in. A user whose role changes so they can no longer use
the admin loses access at once. A connection that has not been used for 60
days asks to log in again.

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
- The zip contains a `VERSION` file. When it differs from the version the site
  last ran (`storage/framework/deployed-version`), `App\Support\Release` clears
  the compiled views and `bootstrap/cache` once, so an update by zip never
  shows old pages.
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
| Payment | `app/Engines/PaymentEngine.php`, `app/Payments/` | Mollie, Stripe and a test provider behind one interface; every payment records whether it used a test key |
| Calendar | `app/Engines/CalendarEngine.php` | iCal import and export |
| Documents | `app/Engines/DocumentEngine.php` | Invoices, confirmations and receipts as PDF |
| Notifications | `app/Engines/NotificationEngine.php` | Guest emails |
| Pricing services | `app/Services/*Provider.php` | PriceLabs, Beyond, Wheelhouse |
| Admin | `app/Filament/` | Filament screens |
| AI connection | `app/Mcp/`, `routes/ai.php`, `app/Support/McpConnection.php` | MCP server (laravel/mcp) with OAuth through Passport for the apps the owner allows; every tool checks the same rights as the admin (`app/Support/Access.php`) |

Amounts are stored in cents. Everything is date-based: one night is one date.

### Security rules worth knowing

- **Rights**: everything goes through `app/Support/Access.php`, also outside the
  Filament screens (media manager, price calendar, API, AI connection). An
  action someone may only look at is hidden *and* refused on the server.
- **Uploads** are served from the site's own domain, so nothing uploaded may
  run there: photos are saved under the type of their content (not the name),
  and only the owner can add SVG files. `public/.htaccess` sends `nosniff` and
  sandboxes SVG files.
- **Addresses the server fetches** (iCal calendars, listings and photos to
  import) go through `app/Support/OutboundUrl.php`: http(s) only, never an
  address inside the server's own network (also not after a redirect), and a
  size limit.
- **Limits** on public endpoints: booking, contact and forms, the chat, the
  price and calendar API (at most two years of nights per request), search
  suggestions and photo tracking.
- **Live, links use `APP_URL`** (also in mails), never the Host header of the
  request; the session cookie is https-only when `APP_URL` is https.
- **Headers**: `nosniff` and a referrer policy everywhere; the admin, My
  bookings, the installation and invitations cannot be shown inside another
  site. Accommodation pages stay embeddable for the embed code.
- **The test payment provider** only opens through the signed link the
  booking flow gives; never leave it switched on for a live site.

### Scheduled tasks and commands

| Command | Schedule | What it does |
|---|---|---|
| `heartbeat` | every minute | Tells the dashboard that the cron job runs |
| `queue:work` | every minute | Sends the queued emails |
| `channels:sync` | every 30 minutes | Syncs iCal calendars |
| `prices:sync` | daily at 05:00 | Fetches dynamic prices |
| `identity:process` | daily at 08:45 | ID check after payment: reminds guests, then cancels or notifies you |
| `mails:send-scheduled` | daily at 09:00 | Sends scheduled guest emails |
| `deposits:process` | daily at 09:30 | Refunds security deposits, or reminds the host |
| `lang:translate es` | by hand | Machine-translates a language file from English (DeepL) |
| `admin:disable-two-factor` | by hand | Disables two-factor sign-in for one account |
| `bookings:clear-test-data` | by hand | Removes test bookings before going live and restarts the numbering |

### API

`GET /api/v1/properties`, `/properties/{id}`, `/properties/{id}/quote` and
`/properties/{id}/unavailable-dates` are public. `/api/v1/bookings` requires a
Sanctum token and follows the rights of that user in the admin: the permission
for bookings, and a host only sees and books their own accommodations.
