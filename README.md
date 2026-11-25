# Private Updater, Grav plugin

An **Update button for your private Grav packages**.

Grav's own Update button (GPM) only knows the public plugins and themes of getgrav.org. If your theme or plugins live in private git repos, Grav can clone them once (`bin/grav install` reads `user/.dependencies`) but it never updates them after that. I use the same private theme and plugins on several association websites, so I needed a clean way to roll a fix out to each site, and to roll it back if something goes wrong. This plugin is that missing piece.

It reads the `git:` packages of `user/.dependencies`, shows for each one the version installed, the version pinned and the latest release tag, and checks out a new tag in place when you ask.

## How it works

Every package is pinned to a **release tag** in `user/.dependencies`, which is Grav's own format:

```yaml
git:
  my-theme:
    url: git@github.com:me/my-theme.git
    path: user/themes/my-theme
    branch: v1.2.0        # a tag, not a branch
```

To release a new version of a package, I tag it (`git tag v1.3.0 && git push --tags`). Then on each site I can update it from the admin panel, or from the terminal:

```bash
bin/plugin private-updater status            # pinned / installed / latest for each package
bin/plugin private-updater update my-theme   # latest tag (or give one: update my-theme v1.2.0)
bin/plugin private-updater sync              # install what is missing, check out the pinned tags
```

`sync` is the deploy step: if the site's `user/` folder is a git repo, `git pull` brings the new pins and `sync` applies them.

An update checks out the tag, writes it back into `user/.dependencies` (only the `branch:` line, so your comments stay) and clears the cache. Going back to the previous version is the same thing with the old tag.

## Safety

The update runs git on the server, so I was careful with it:

- Only the URLs and paths written in `user/.dependencies` are used. The only input from the admin is a tag, and it must look like a release (`v1.2.3`) **and** exist on the remote.
- A package with modified files is never touched. If someone edited code directly on the server, the update stops and tells you to look at `git status` first.
- A symlinked package is never touched either. That is my development setup (`bin/grav install -s`), where the package is my working clone.
- One update at a time (file lock), and every update is written to `logs/private-updater.log`.
- The admin page and the API are for **super-admins only**.

## Installation

Requirements: Grav 2, the API plugin (it comes with Grav 2), and `git` on the server.

```bash
cd user/plugins
git clone https://github.com/Alliance-Francaise/grav-plugin-private-updater.git private-updater
```

Or add it to your `user/.dependencies` and let `bin/grav install` clone it.

**Private repos:** git uses the SSH setup of the account running PHP (`~/.ssh/config`). If you want a dedicated key, set its path in the plugin settings (`ssh_key`). I recommend a read-only GitHub "machine user" with one key per server: a deploy key can only be attached to one repo, so it doesn't scale to several private packages.

## Limitations

- When you update from the admin panel, the server's `user/.dependencies` changes. If the site's `user/` folder is itself a git repo, commit that change on your side too, or the next `git pull --ff-only` will refuse (which is a good warning, but still a warning).
- Only release tags are listed, not branches. That is on purpose: a site should run a version you released, not whatever is on `main`.

## License

MIT
