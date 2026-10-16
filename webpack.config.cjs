const path = require('node:path');
const fs = require('node:fs');

/** Add the document editor to the consuming application's Encore build. */
module.exports = function configureDocumentEditor(Encore, projectDir = process.cwd()) {
  const resolvePackage = (name) => {
    let dir = path.dirname(require.resolve(name, { paths: [projectDir] }));
    while (dir !== path.dirname(dir)) {
      const manifest = path.join(dir, 'package.json');
      if (fs.existsSync(manifest) && JSON.parse(fs.readFileSync(manifest, 'utf8')).name === name) {
        return dir;
      }
      dir = path.dirname(dir);
    }
    throw new Error(`Package introuvable : ${name}. Installez les dépendances de l’éditeur avec Yarn.`);
  };

  Encore
    .addEntry('conception-document-editor', path.join(__dirname, 'assets/editor/main.ts'))
    .enableTypeScriptLoader((options) => {
      options.configFile = path.join(__dirname, 'tsconfig.json');
    })
    .copyFiles({
      from: path.join(resolvePackage('pagedjs'), 'dist'),
      pattern: /paged\.polyfill\.js$/,
      to: 'conception-document/paged.js',
    })
    .copyFiles({
      from: path.join(__dirname, 'assets/editor'),
      pattern: /legacy\.js$/,
      to: 'conception-document/legacy.js',
    });

  for (const family of ['arimo', 'tinos', 'gelasio', 'montserrat', 'libre-barcode-128']) {
    const root = resolvePackage(`@fontsource/${family}`);
    Encore.copyFiles({
      from: path.join(root, 'files'),
      pattern: /-latin-(400|700)-(normal|italic)\.woff2$/,
      to: 'conception-document/fonts/[name].[ext]',
    }).copyFiles({
      from: root,
      pattern: /LICENSE$/,
      to: `conception-document/fonts/${family}-LICENSE`,
      includeSubdirectories: false,
    });
  }

  return Encore;
};
