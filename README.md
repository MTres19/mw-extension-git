# MediaWiki Git Extension

The goal of this project is to implement a special page Special:GitAccess to allow access to the content of a MediaWiki wiki via Git. The primary goals in comparison to [Git-MediaWiki](https://github.com/Git-MediaWiki/Git-MediaWiki/wiki/) are:

- **Reliable:** Since this is implemented as an extension, it will be able to store data that MediaWiki would otherwise be unable to store, yet are essential for proper operation of Git (e.g. commit hashes).
- **Fast:** Unlike Git-MediaWiki, the extension will not have to send a lot of HTTP requests for each revision of each page. Instead, it can read all the edit history and content from the database, compress it into Git objects, and send them to the Git client.
- **Configurable:** After core features are done, there will be the possibility for enhancements like syncing with a remote Git repository or MediaWiki magic words to prevent pages from being accessed through Git.
