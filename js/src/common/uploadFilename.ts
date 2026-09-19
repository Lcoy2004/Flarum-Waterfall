/**
 * The extension to put in a multipart `filename=`.
 *
 * The browser takes the file part's name from the File object, so a Chinese
 * filename — the norm on this site — reaches the server as raw UTF-8 bytes
 * inside the part header. Reverse proxies and firewalls that re-parse
 * multipart bodies (BaoTa's form-data rules among them) can read that as a
 * malformed request and block the whole upload. Quotation marks and
 * backslashes in a name break the header the same way, so the name is
 * rebuilt from ASCII-only pieces.
 *
 * Only the extension matters downstream: the server renames the bytes to a
 * random spool name and the image host renames them again, picking the
 * served format from the extension it is handed. The MIME subtype is the
 * browser's own reading of the file and wins over the name's tail; the name
 * is the fallback for a file the browser could not type. Neither is trusted
 * for content — the server sniffs the magic bytes — so this is purely about
 * keeping the header well formed.
 */
export function uploadExtension(mimeType: string, name = ''): string {
  const fromMime = /^[a-z]+\/([a-z0-9]{1,8})$/i.exec(mimeType.trim())?.[1];

  if (fromMime) {
    return fromMime.toLowerCase();
  }

  const fromName = /\.([a-z0-9]{1,8})$/i.exec(name.trim())?.[1];

  return fromName ? fromName.toLowerCase() : 'jpg';
}
