// Configuration for web-ext (npm run lint / build / sign).
//
// Everything listed here is development scaffolding that must stay out of the shipped
// package. web-ext already excludes node_modules, dotfiles and its own artifacts
// directory, but not the files below.
module.exports = {
    ignoreFiles: [
        'package.json',
        'package-lock.json',
        'web-ext-config.js',
    ],
};
