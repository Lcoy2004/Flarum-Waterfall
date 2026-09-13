module.exports = require('@flarum/jest-config')({
  moduleNameMapper: {
    '^flarum/(.*)$': '<rootDir>/../../../flarum/core/js/src/$1',
    // @flarum/jest-config's own setup module imports core internals by package
    // path (`@flarum/core/src/...`), which the alias above does not cover — so
    // the suite could not even start ("Cannot find module
    // '@flarum/core/src/common/utils/mixin'"). Same target, second alias.
    '^@flarum/core/(.*)$': '<rootDir>/../../../flarum/core/js/$1',
  },
});
