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
`SURNAME_FREQUENCY_EXPONENT` (1.5, filterable via
`wcos_surname_frequency_exponent`). The fixture's own curve is nearly flat - the
most common surname is 0.47% of rows - which makes every search term about as
selective as every other one and never asks an index a hard question. At 1.5 the
head lands near 1.1% with a long tail behind it.

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
Replayed over 50,000 orders against the shipped fixture:

| Criterion | Target | Measured |
| --- | --- | --- |
| Distinct surnames | >= 2000 | 2,991 |
| Rows with an empty given or family name | < 1% | 0.000% |
| Most common surname | 1-2% | 1.14% (`Williams`) |
| Name/email pairs that disagree | 0 | 0 |

### Other commands

    wp wcos seed --force                        # reload the fixture table
    wp wcos refresh-customers --last-name=Example  # re-identify stale customers

`refresh-customers` is opt-in on purpose - it rewrites names on existing customer
records. It does not rewrite orders that were already generated; a store whose
history predates 1.2.0 needs regenerating to measure clean.
