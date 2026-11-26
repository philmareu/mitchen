# Contributing to Mitchen

Mitchen is a personal project. I built it for my own kitchen, I use it every day, and I maintain it alone in my spare time. It's licensed under the [AGPL-3.0](LICENSE) and not commercially supported. If you host it yourself, you do that at your own risk.

Issues and pull requests are open, because a bug report from another kitchen is worth a lot. What I can't offer is a quick reply, or a promise to take up every request. This page says what to expect, so you know before you put time in.

## What to expect

- **Bug reports** are welcome. Use the [bug report form](https://github.com/philmareu/mitchen/issues/new/choose). A report may sit for a while before I get to it. Issues are public, so leave out `.env` contents, access tokens, your server's address and unredacted logs.
- **Feature requests** can be filed, but I may close one without action if it doesn't fit what I need from Mitchen. Forking is encouraged, and for most "I wish it did X" it's the right answer.
- **Pull requests** that are small, focused bug fixes with a test are the most likely to be merged. For anything larger, open an issue first. I may decline a large pull request I wasn't expecting, however good it is. Review may be slow. Read [Terms for contributions](#terms-for-contributions) before you send one.
- **Security vulnerabilities** never go in a public issue. Report them privately, as [SECURITY.md](SECURITY.md) describes. They come first.
- **Hosting PHP, and how an assistant behaves,** are outside what I can help with. [Laravel's deployment guide](https://laravel.com/docs/deployment) covers the first. For the second, Mitchen only decides what its MCP tools take and return; what Claude or another client does with them is up to that client.
- There are no Discussions, no wiki and no chat. Issues and pull requests are all there is.

## Terms for contributions

Mitchen's license is the AGPL-3.0: anyone can run, change and share it, and whoever runs a changed copy for other people has to offer them its source.

I may one day offer a hosted Mitchen, and a hosted service can need parts that aren't in this repository, such as billing. I can only do that with code I hold the rights to. So a pull request is accepted on these terms:

- You wrote the contribution, or you have the right to submit it.
- It's licensed to everyone under the AGPL-3.0, like the rest of Mitchen.
- You also give me, Phil Mareu, a perpetual, worldwide, royalty-free right to use your contribution and to license it under other terms, including in a hosted or commercial version of Mitchen.

You keep the copyright to what you wrote. If these terms don't suit you, open an issue that describes the fix instead, and I'll write it.

## Getting set up

You need PHP 8.4 or 8.5 and Composer 2. The [README](README.md) lists the PHP extensions.

```bash
git clone https://github.com/philmareu/mitchen.git
cd mitchen
composer setup               # install dependencies, create .env, an app key and a SQLite database
php artisan migrate --seed   # create the tables and load the demo
php artisan serve
```

Open the URL it prints and sign in as `test@example.com` with the password `password`.

The demo is a made-up kitchen: ingredients at every stock level, a shopping list, a few past shops, equipment and eight recipes. Its login has a password anyone can look up, so the demo refuses to load in production, in a database that already has another login, and into a kitchen that already has something in it. `php artisan migrate:fresh --seed` starts it over.

To try the MCP server, run `php artisan passport:keys` once, then point a client such as Claude Code at the `/mcp` address of the server you started.

## Before you open a pull request

- `composer test` passes. The tests run on SQLite in memory and call no outside service. Keep it that way.
- `vendor/bin/pint` has formatted your code, and `vendor/bin/phpstan analyse` reports nothing.
- New behavior has a test. Helpers such as `actingAsCook()` and `sentRecipe()` live in `tests/Pest.php`.
- No real keys, tokens or photos of anyone's home anywhere: not in code, fixtures, screenshots or commit messages.
- It does one thing. For anything larger than a bug fix, open an issue first so we can agree on the approach before you write it.

GitHub runs the tests on PHP 8.4 and 8.5, plus Pint and Larastan, on every pull request. On your first pull request, the run waits for me to approve it.

## Conventions

- **Mitchen makes no AI calls.** It has no AI SDK and no API key, and that stays. What an assistant can do belongs in the MCP server (`app/Mcp`), where the cook's own Claude does the understanding.
- Business logic lives in `app/Actions`, and helpers with no side effects in `app/Support`, not in Filament classes. MCP tools are thin: they check what they're sent and call the same actions the forms use.
- A quantity is read and written through the `quantity` attribute (`App\Support\Quantity`), never through its three columns.
- Query scopes live in the custom builders under `app/Models/Builders`.
- Recipe text, notes and names can come from a web page by way of an assistant. Show them as text, never as HTML, and keep the MCP server's instruction that they are content, not instructions.
- Mitchen has no custom Filament theme, so Tailwind classes in Filament schemas aren't compiled. Use Filament's own components and options.
- Tests run on SQLite, but Mitchen has to work on MySQL too. Keep queries driver-neutral.
- A migration that has shipped is never edited. Add a new one.

`CLAUDE.md` is written for AI coding agents, and is also the most complete description of the domain rules.
