# Order Simulator for WooCommerce
## Download [WooCommerce] (http://www.woothemes.com/woocommerce/)

Welcome to the Order Simulator for WooCommerce. Like many developers, we struggle with building test sites with the type of (and enough) order data to make testing valid across a number of scenarios and at scale. For [Follow Ups] (http://www.woothemes.com/products/follow-up-emails/), we needed the ability to test many thousands of emails per day similar to many of our customers, and hence the Order Simulator was born.

## Support

Please note that we will provide support as necessary for this plugin, but we cannot guarantee it. We released this plugin as a service to developers and site owners. We do welcome improvements and pull requests, so fork this repository and share back your edits, fixes, etc. We will review them posthaste.

## How it works

* Download this repo
* Upload order simulator to your `/wp-content/plugins/`
* Activate the plugin
* Go to `WooCommerce > Settings`
* Choose `Order Simulator` to set your order settings
* You can:
  * Define the number of orders created per hour (turn off by setting to `0`)
  * Limit the products that can be added to each order (leave blank to allow any product)
  * Limit the minimum number of products per order
  * Limit the maximum number of products per order
  * Set the percentage of orders that go to `Completed`, `Pending Payment`, or `Failed` status
* Set your `Create User Accounts` settings
  * We always recommend testing with email turned off, using an SMTP service in test mode, or otherwise.
  * When installing, a table will be installed called `fakenames` from the `fakenames.sql` which includes a random database of names and emails (auto-generated and _fake_ to the best of our knowledge)
  * If you have `Create User Accounts` to `No` then the orders will be assigned to existing users
  * If you have `Create User Accounts` to `Yes` then the orders will be assigned to new users created using the `fakenames.sql` data, and from existing users
* Please make sure that you have `BACS` payments turned on

## Data realism (1.2.0)

Generated orders are meant to be searchable, not just numerous. 1.2.0 fixes two
defects that made them the second thing but not the first.

**Surnames had collapsed to one value.** A 2020 privacy sweep rewrote the fixture
so nothing in it could be mistaken for reachable contact data - emails to
`@example.com`, phone numbers to 555. The same sweep also overwrote the `surname`
column with the literal string `Example`, taking 4,308 distinct surnames down to
1. The surname column is restored; the email and phone scrub stays, because that
part was the point.

Surnames are drawn with their fixture frequency raised to the power
`SURNAME_FREQUENCY_EXPONENT` (1.7, filterable via
`wcos_surname_frequency_exponent`). The fixture's own curve is nearly flat - the
most common surname is 0.47% of rows - which makes every search term about as
selective as every other one and never asks an index a hard question. The
exponent was swept against a replay rather than computed, because customer reuse
flattens the realised distribution:

| Exponent | Distinct surnames | Most common |
| --- | --- | --- |
| 1.5 | 3,123 | 0.97% - head too flat |
| **1.7** | **2,488** | **1.31%** |
| 1.8 | 2,197 | 1.39% |
| 1.9 | 1,900 - under the floor | 1.50% |

### The rest of the columns

The same collapse-to-one-value problem applied elsewhere, so 1.2.0 also
regenerates:

**Maiden names** are a shuffled copy of the surname column - 4,308 distinct, same
long tail, never a row's own surname. Drawing them from the weighted corpus
instead loses variety badly (10,000 draws only turn up ~1,900 distinct names), and
a real population's maiden names follow the surname distribution anyway.

**Emails** are composed from the name across seven patterns - `jane.doe`,
`janedoe`, `jdoe`, `jane_doe`, `doe.jane`, `jane-doe`, `j.doe` - so a lookup
feature cannot pass by getting one substring rule right. Every pattern keeps the
surname whole, which is what lets a search by name and a search by email agree.
The clean form is tried first and digits only appear once it collides, so early
customers get `j.doe@` and later ones `j.doe4471@`, the way real mailboxes fill
up.

Domains are weighted across twelve names, one dominant at 35%, because a flat
spread makes every domain equally selective - the same mistake the flat surname
distribution made, just moved to another column. All twelve are unregistrable:
RFC 2606 reserves `example.com/.net/.org` and the `.example`, `.test` and
`.invalid` TLDs. Note that `etest.com` and `sample.com` are **not** safe for this
- both are ordinary registrable `.com` names owned by someone else, so a store
with mail enabled would aim real messages at a real domain.

**Phone numbers** were the worst of the lot: one value, `555-555-5555`, across all
10,000 rows. Zero selectivity, and `555-555-5555` is not even in the range that is
actually reserved. They are now generated per order in the country's own format:

| | Range | Status |
| --- | --- | --- |
| US, CA | NANP `555-0100..555-0199`, varied area code | officially reserved |
| GB | Ofcom drama ranges (`01632 960xxx`, `020 7946 0xxx`, `07700 900xxx`) | officially reserved |
| AU | ACMA drama ranges (`(0x) 5550 1xxx`, `0491 570 xxx`) | officially reserved |
| FR | ARCEP fiction ranges (`01 99 00 xx xx` and per-zone equivalents) | officially reserved |
| NZ, BE, BR | national format with a `555`/`5550` subscriber block | **convention only** |

NZ, BE and BR publish no fictional range this plugin can cite, so those ~38% of
rows follow the usual fake-number convention without a guarantee the number is
unallocated. Nothing here dials anything, so the exposure is a human copying a
number out of test data - small, but real, and worth knowing about. Making the
fixture US-dominant would remove the caveat entirely if that ever matters more
than the international spread.

**A third of orders had no address at all.** `create_user()` never checked what
`wp_insert_user()` returned. Once the 10,000-row fixture saturated, inserts started
failing on duplicate email - the uniqueness pre-check only looked at `user_login` -
and the resulting `WP_Error` flowed into `get_user_meta()`, which answers `''` for
every key. The order was written with every address field blank. The return is now
checked, and an order is skipped rather than written without an address it can be
found by. Given names and surnames are also drawn independently instead of read off
a single fixture row, so the identity space is 1,340 x 4,308 rather than 10,000 and
cannot run dry.

Order fields are written through the WooCommerce CRUD layer rather than
`update_post_meta()`, which under HPOS addresses the legacy post rather than the
orders table.

### Verifying

    wp wcos verify

Measures the live order address data against each criterion and prints PASS/FAIL.
Replayed over 50,000 orders through the plugin's own generator:

| Criterion | Target | Measured |
| --- | --- | --- |
| Distinct surnames | >= 2000 | 2,488 |
| Rows with an empty given or family name | < 1% | 0.000% |
| Most common surname | 1-2% | 1.31% (`Williams`) |
| Name/email pairs that disagree | 0 | 0 |
| Distinct phone numbers | >= 2000 | 20,763 |
| Most common phone number | < 1% | 0.042% |
| Distinct email domains | > 1 | 12 |
| Most common email domain | < 60% | 35.0% |
| Rows on a domain that is not RFC-reserved | 0 | 0 |

For reference, the fixture itself now carries 4,308 distinct surnames, 4,308
maiden names, 10,000 emails, 9,200 phone numbers and 5,840 cities.

### Other commands

    wp wcos generation 0 --unschedule               # stop generating orders
    wp wcos clean-options                           # remove the stray per-field options
    wp wcos seed --force                            # reload the fixture table
    wp wcos refresh-customers --last-name=Example   # re-identify stale customers
    wp wcos reidentify-orders --dry-run             # see what would change on orders
    wp wcos reidentify-orders --batch=2000 --yes    # repair a batch of orders

Subcommand names use hyphens. WP-CLI derives a subcommand from the method name
verbatim, so the hyphenated forms need an explicit `@subcommand` annotation -
added in 1.2.2. A 1.2.1 build in the field only answers to the underscore forms
(`wcos clean_options`, `wcos refresh_customers`, `wcos reidentify_orders`).

## The settings screen used to ignore edits

Fixed in 1.2.1. Change "Orders per Hour", hit Save, and the old number came back.

Every field on this page keeps its value inside one option,
`wc_order_simulator_settings`, and each field's `id` is only a form field name.
WooCommerce does not know that: `WC_Admin_Settings::output_fields()` renders a
field from `get_option( $field['id'], $field['default'] )`, treating the id as an
option name of its own. Those options exist - all eight of them were sitting in
the sandbox's options table - so the form showed the stray option's value rather
than the stored setting. `save()` then read the POSTed (displayed) number and
wrote it into the real settings, so hitting Save actively reinstated the stale
value.

Each field now passes an explicit `value`, which `output_fields()` uses in
preference and which stops it consulting `get_option()` at all. That holds
whatever stray options exist. `wp wcos clean-options` removes them anyway;
it names the eight explicitly rather than matching `wcos_*`, because the plugin's
own bookkeeping shares that prefix and a wildcard delete would take
`wcos_fakenames_version` with it and silently trigger a reseed.

What originally created those options is not established. Nothing in the plugin
writes them today.

## Repairing a store that predates 1.2.0

Orders written before 1.2.0 keep their bad identity data - the fix changes what
gets generated, not what is already on disk. You do not have to delete them
though. The orders are fine apart from four columns: dates, totals, line items
and the status mix are all correct, and regenerating throws that away along with
several hours.

`reidentify-orders` rewrites only the identity columns, going through the
WooCommerce CRUD layer so the orders table, the synced post meta and the lookup
tables stay in agreement. Direct SQL would be perhaps a hundred times faster, but
hand-maintaining that fan-out is exactly the class of mistake being repaired here.

    wp wcos generation 0 --unschedule
    wp wcos refresh-customers --last-name=Example
    wp wcos reidentify-orders --dry-run
    wp wcos reidentify-orders --batch=2000 --yes     # repeat until it reports 0

Do not stop the generator with `wp option update wc_order_simulator_settings
--format=json '{"orders_per_hour":0}'`. That replaces the whole option, so the
product selection and the status split silently fall back to defaults - a store
set to 100% completed quietly becomes 90/5/5. Use `wp wcos generation`, or
`wp option patch update wc_order_simulator_settings orders_per_hour 0`, both of
which change one key and leave the rest alone.

Order matters. `reidentify-orders` copies what the customer record currently
says, so refreshing customers has to come first - otherwise it faithfully copies
the names you are trying to replace. It warns if you forget.

Stopping the generator first matters too, or it keeps appending fresh orders to
the end of the range while you are repairing the start of it.

Batches are keyed on the order ID and the resume point is stored, so a process
the host kills picks up where it stopped rather than starting over. `--all` runs
to completion in one go where that is safe. `--dry-run` writes nothing and also
reports which customer IDs sit behind the orders, which is the quickest way to
see what the blank-address orders were attached to.

Two things it does not do:

- **The trigram index is not rebuilt.** `wp_fwol` is ~4.3M rows derived from
  order data and goes stale. Deactivating Fast Woo Order Lookup drops the table,
  reactivating rebuilds it. If the point of the exercise is measuring whether
  that index helps, take the baseline with it off first.
- **The analytics lookup tables are not regenerated.** `wp_wc_customer_lookup`
  keeps its own copy of customer names. It is off the critical path for order
  search - `wc_get_orders( 's' => ... )` reads the orders and addresses tables -
  but it will disagree until regenerated from WooCommerce > Status > Tools.

## Building and deploying

    npm run package        # -> zip_files/order-simulator-woocommerce.zip

Uses `../trs-package.js` and the declared `trsPackage.include` payload, per the
suite's build contract. Not `wp-scripts plugin-zip`, which writes a flat archive
with no `<slug>/` wrapper - and the folder inside the zip becomes the installed
plugin directory, so a flat archive installs a second copy rather than updating
in place.

The payload is four files. There is no compile step; the `build/`, `index.js` and
webpack config in this repo are left over from a build-process experiment and
nothing enqueues them.

On load, a version bump to the fixture reseeds the `fakenames` table
automatically - no deactivate/reactivate needed.
