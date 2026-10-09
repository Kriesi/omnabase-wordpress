# OmnaBase for WordPress

The WordPress plugin that connects a site to [OmnaBase](https://app.omnabase.com), the support inbox: forum topics become tickets and the
team's answers come back to the forum, the team sees a customer's orders and licences on every ticket, a shortcode form sends questions,
and logged-in customers read their own tickets on the site. It talks to OmnaBase's public API only.

Status: in development (M13, round zero: the workshop). Not released.

## Working on it

```sh
composer install            # PHP 8.1+; the plugin's autoloader and the dev tools
npm install                 # Node 22: wp-env and Playwright
npx wp-env start            # WordPress on http://localhost:8888 (tests on 8889), Docker needed
bin/seed.sh                 # the fixtures: customers, orders, forums
composer lint && composer test
composer test:integration   # inside wp-env
npx playwright test         # needs OmnaBase's e2e server, see CLAUDE.md
bin/build-dist.sh           # build/dist/omnabase-<version>-dev.zip
```

The rules for working here, the test layers and the local network are in `CLAUDE.md`; the decisions in `DECISIONS.md`; the customer
changelog in `changelog.txt`.

Licence: GPL-2.0-or-later.
