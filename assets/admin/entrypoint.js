// Front-end entry point for the admin, expected by the test application's webpack.config.js as by
// any Sylius application:
// `addEntry('plugin-admin-entry', '<plugin>/assets/admin/entrypoint.js')`.
//
// This plugin ships no JavaScript: the gateway configuration form is Twig, rendered by a hookable.
// The file is nonetheless required - without it `yarn build` fails and every admin page answers
// 500.
