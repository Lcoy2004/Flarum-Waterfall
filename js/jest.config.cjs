// `@flarum/jest-config` already resolves Flarum core itself — `vendor/flarum/
// core/js` beside `js/` for a Composer-installed extension, the `@flarum/core`
// package in a monorepo — and derives both module aliases from wherever it
// lands (`^flarum/*` -> <core>/src, `^@flarum/core/*` -> <core>).
//
// This file used to re-declare those two aliases as paths relative to the
// extension's location inside a site (`../../../flarum/core`). That resolves
// for a working copy under `vendor/`, but on a plain clone — which is what CI
// checks out — those three levels leave the repository, so the aliases pointed
// at nothing and the suite could not even load. Worse, a passed
// `moduleNameMapper` replaces the one above rather than extending it. Nothing
// here needed overriding, so the resolved mapping is left in place.
module.exports = require('@flarum/jest-config')();
