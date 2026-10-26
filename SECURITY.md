# Security policy

This package runs inside every request of the apps that install it, and it
handles their errors, which can carry their users' data. Treat a problem in it
as you would a problem in a logging library that sees production traffic.

## Reporting a vulnerability

Email **security@naijacloud.com**. Include what you found, how to reproduce
it, and what an attacker gets from it.

Please do **not** open a public issue, a pull request that fixes it quietly,
or a discussion thread. A public patch is a disclosure.

We aim to acknowledge within two working days (West Africa Time, UTC+1), and
to have either a fix or a dated plan within ten.

## Supported versions

The latest minor release is supported. While the package is pre-1.0 that
means 0.1.x. Security fixes are released as a patch and noted in
`CHANGELOG.md`.

## What this package guarantees

These are enforced in code and covered by tests. A break in any of them is a
security bug:

- **Sensitive values are filtered before they leave the process.** That
  covers the values of sensitive keys (password, token, secret,
  authorization, cookie, card, bvn and the others in `argus.sdk/1` section 7)
  at any depth. It also covers card numbers that pass Luhn, bearer and basic
  credentials, JWTs, and `key=value` secrets inside strings, including source
  lines sent as stack-trace context.
- **Request bodies and cookies are never sent.** Query strings are removed
  from request and span URLs.
- **IP addresses are not sent** unless the app sets `send_default_pii`.
- **Redirects are never followed,** so a response cannot send the app's data
  to another host.
- **TLS verification stays on.** There is no option to turn it off.
- **The SDK never throws into the app.** A broken network, a hostile value or
  a misbehaving hook cannot take a request down.

## What it does not guarantee

- **Your own fields:** anything you put under a key the filter does not
  recognise is sent as given. Use `before_send` for fields specific to your
  app.
- **The DSN is not a secret.** Its public key can only send data to one
  source, and it is safe in a browser. It is still kept out of `var_dump()`
  output so it is not mistaken for a leak.
