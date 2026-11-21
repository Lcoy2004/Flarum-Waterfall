/**
 * Flarum's `slidable` is shipped as plain JS by core, so it has no bundled
 * types. Only the shape this extension uses is declared.
 */
declare module 'flarum/forum/utils/slidable' {
  export default function slidable(element: Element | SVGElement): { reset: () => void };
}
