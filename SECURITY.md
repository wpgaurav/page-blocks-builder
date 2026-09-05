# Security policy

## Reporting

Email **gaurav@gatilab.com** with "GT Page Blocks Builder" in the subject.
Please include the plugin version, WordPress version, PHP version, and the
smallest set of steps that reproduces the issue. Please do not open a public
GitHub issue for a vulnerability.

You will get an acknowledgement within a few days. If the report is valid you
will be told when a fix is planned and credited in the release notes unless you
ask not to be.

## Supported versions

| Version | Security fixes |
|---|---|
| 3.0.x | Yes |
| 2.8.x | Until 2027-03-01 |
| Older | No |

## Security releases reach every install

The plugin is free to use and a licence buys automatic updates. That model
would ordinarily mean an unlicensed site never receives a security fix, which
is not an acceptable trade.

So security releases travel on their own channel. Every install checks a static
manifest twice a day and is offered the update when its version is below the
minimum safe version named there. The check sends the plugin version and
nothing else. It is gated so it can only ever offer the release named as the
fix, never a feature release, and the download URL is rejected unless it is on
the plugin's own host. Disable it with:

```php
define( 'GT_PB_DISABLE_SECURITY_CHANNEL', true );
```

Every security release is also attached to a public GitHub release, so a manual
download path exists that depends on no infrastructure of ours.

## Things worth knowing before you file

Two behaviours are deliberate and documented rather than defects:

- **PHP execution in blocks** is off unless the site defines `GT_PB_ALLOW_PHP`,
  and inline blocks need `GT_PB_ALLOW_INLINE_PHP` on top of it. With those set,
  an administrator can run PHP - which is equivalent to the plugin-file editing
  an administrator already has.
- **Block HTML, CSS and JS are stored as written.** Authoring a block requires
  `manage_options`, and the capability is the boundary; the markup is not
  filtered on the way in, because filtering it would break the feature.

A report that a user *without* those capabilities can reach either of the above
is a vulnerability, and we want to hear about it. That is precisely the class of
bug fixed in 2.8.1.
