# Security policy

Mitchen holds a household's kitchen, and it lets an assistant such as Claude read and change it after signing in with OAuth. A hole in either the app or that sign-in matters, so security reports come before everything else on this project.

## Reporting a vulnerability

Don't open a public issue or pull request for a vulnerability. Report it through [GitHub's private vulnerability reporting](https://github.com/philmareu/mitchen/security/advisories/new). Only you and I can see the report.

It helps to include:

- The commit you tested. `git rev-parse --short HEAD` prints it.
- What an attacker can do, and what they need first: nothing, a login, an approved MCP client, or a way to get text into a recipe.
- Steps to reproduce it, or a proof of concept. The demo kitchen is enough.
- A fix, if you have one in mind.

Leave out anything from your `.env`, access tokens and your server's address, even though the report is private.

## What to expect

I maintain Mitchen alone in my spare time. I can't promise a response time, and there is no bug bounty. Security reports are the first thing I look at, and I'll reply on the report as soon as I can.

When a report holds up, I fix it on `main` and publish a GitHub security advisory, with credit to you unless you'd rather not be named. Please give me the chance to do that before you publish the details.

## Scope

In scope is the code in this repository. For example:

- Reaching the kitchen, a photo or the MCP server without signing in.
- An MCP client getting a token without a signed-in user approving it, or keeping access after it should have lost it.
- Text saved in a recipe, a note or a name running as script in someone's browser.
- A signed-in user or an approved client reading files, or reaching anything, beyond the kitchen.

Out of scope:

- Everyone who signs in shares the one kitchen. That is how Mitchen works: there are no roles, and one login seeing or changing what another added is not a vulnerability.
- How a particular server is set up: HTTPS, the web server, file permissions, backups, or a `.env` that can be read from the web. That's up to whoever hosts it.
- What an assistant decides to do with what it reads. Mitchen tells MCP clients to treat recipe text, notes and names as content and never as instructions, and its tools ask for changes to be read back before they're saved, but it can't control a model. A way to make Mitchen itself act on such text is in scope.
- Vulnerabilities in Laravel, Filament, Passport or another dependency, unless the way Mitchen uses it is what makes it exploitable. Report those to the project they belong to.

## Supported versions

There are no tagged releases yet. Security fixes go to the `main` branch, so the latest commit on `main` is the only supported version. To get a fix, update to it.

To hear about fixes, watch this repository's security advisories: **Watch**, then **Custom**, then **Security alerts**.
