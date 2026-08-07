#!/usr/bin/env node

/*
 * Contao Multilingual Pagetree
 * Package: vtinnovations/contao-multilingual-pagetree
 * Copyright: V&T Innovations Team
 * Licence: proprietary
 * Website: https://www.v-t.one
 */

'use strict';

const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const failures = [];
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');
const files = (directory, suffix = '') => {
  const result = [];
  const walk = (current) => {
    for (const entry of fs.readdirSync(current, {withFileTypes: true})) {
      const full = path.join(current, entry.name);
      if (entry.isDirectory()) walk(full);
      else if (!suffix || entry.name.endsWith(suffix)) result.push(full);
    }
  };
  walk(path.join(root, directory));
  return result;
};

const composer = JSON.parse(read('composer.json'));
if (composer.name !== 'vtinnovations/contao-multilingual-pagetree') failures.push('Composer package identity is incorrect.');
if (composer.extra?.['contao-manager-plugin'] !== 'Vtinnovations\\ContaoMultilingualPagetree\\ContaoManager\\Plugin') failures.push('Manager plugin identity is incorrect.');

const classMap = new Map();
for (const file of files('src', '.php')) {
  const source = fs.readFileSync(file, 'utf8');
  const namespace = source.match(/^namespace\s+([^;]+);/m)?.[1];
  const declaration = source.match(/^(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/m)?.[1];
  if (!namespace || !declaration) continue;
  const fqcn = `${namespace}\\${declaration}`;
  classMap.set(fqcn, file);
  const expected = path.join(root, 'src', ...fqcn.replace(/^Vtinnovations\\ContaoMultilingualPagetree\\?/, '').split('\\')) + '.php';
  if (path.normalize(file) !== path.normalize(expected)) failures.push(`PSR-4 mismatch: ${path.relative(root, file)} => ${fqcn}`);
}

for (const file of files('src', '.php')) {
  const source = fs.readFileSync(file, 'utf8');
  for (const match of source.matchAll(/^use\s+(Vtinnovations\\ContaoMultilingualPagetree\\[^;]+);/gm)) {
    if (!classMap.has(match[1])) failures.push(`Missing internal class ${match[1]} referenced by ${path.relative(root, file)}.`);
  }
}

const plugin = read('src/ContaoManager/Plugin.php');
if (!/function\s+getBundles\s*\(ParserInterface\s+\$parser\)\s*:\s*array/.test(plugin)) failures.push('Manager plugin getBundles() is not forward-compatible.');
const extension = read('src/DependencyInjection/VtinnovationsContaoMultilingualPagetreeExtension.php');
if (!/function\s+load\s*\(array\s+\$configs,\s*ContainerBuilder\s+\$container\)\s*:\s*void/.test(extension)) failures.push('DI extension load() is not forward-compatible.');
if (!/function\s+getAlias\s*\(\)\s*:\s*string\s*\{\s*return\s+'contao_multilingual_pagetree';\s*\}/s.test(extension)) failures.push('DI extension does not preserve the approved alias.');

const bundle = read('src/VtinnovationsContaoMultilingualPagetreeBundle.php');
if (!bundle.includes('use Symfony\\Component\\DependencyInjection\\Extension\\ExtensionInterface;')) failures.push('Bundle does not import ExtensionInterface.');
if (!bundle.includes('use Vtinnovations\\ContaoMultilingualPagetree\\DependencyInjection\\VtinnovationsContaoMultilingualPagetreeExtension;')) failures.push('Bundle does not import its concrete extension.');
if (!/function\s+getContainerExtension\s*\(\)\s*:\s*\?ExtensionInterface/.test(bundle)) failures.push('Bundle does not explicitly override getContainerExtension().');
if (!/return\s+\$this->containerExtension\s+\?\?=\s+new\s+VtinnovationsContaoMultilingualPagetreeExtension\(\);/.test(bundle)) failures.push('Bundle does not return and reuse its intended extension.');

const services = read('src/Resources/config/services.yaml');
for (const required of ['DependencyInjection,Entity,ContaoManager,Model', 'Storage\\PackageStoreInterface:', 'Storage\\RequestLedgerInterface:', 'Distribution\\ChannelTransportInterface:', 'Helper\\Clock:', 'Support\\KeyDirectory:', 'decoration_on_invalid: ignore']) {
  if (!services.includes(required)) failures.push(`services.yaml is missing installation-safety marker: ${required}`);
}

const serviceIds = [];
for (const line of services.split('\n')) {
  const match = line.match(/^    ([^\s#][^:]*):(?:\s|$)/);
  if (match && !['_instanceof', '_defaults'].includes(match[1])) serviceIds.push(match[1]);
}
for (const id of new Set(serviceIds)) {
  if (serviceIds.filter(candidate => candidate === id).length > 1) failures.push(`Duplicate service id: ${id}`);
  if (id.startsWith('Vtinnovations\\') && !id.endsWith('\\') && !classMap.has(id)) failures.push(`Service class does not exist: ${id}`);
}

// Required scalar/private constructors must not remain in the broad PSR-4
// service resource. Explicitly configured services are allowed.
for (const [fqcn, file] of classMap) {
  const relative = path.relative(path.join(root, 'src'), file).split(path.sep).join('/');
  const source = fs.readFileSync(file, 'utf8');
  const constructor = source.match(/(public|private|protected)\s+function\s+__construct\s*\((.*?)\)\s*\{/s);
  if (!constructor) continue;
  const unsafe = constructor[1] !== 'public' || /(?:^|[,\s])\??(?:string|int|bool|float|array|iterable|mixed)\s+\$\w+(?![^,)]*=)/s.test(constructor[2]);
  if (unsafe && !services.includes(relative) && !serviceIds.includes(fqcn)) failures.push(`Broad service resource includes a non-service constructor: ${relative}`);
}

// Services on the registration path must be cheap to build: container
// compilation and contao-setup must never trigger I/O, a query or a request.
const constructorSafeDirectories = ['src/Distribution', 'src/Packaging', 'src/Storage', 'src/Support', 'src/Security', 'src/Metadata'];
for (const file of constructorSafeDirectories.flatMap(directory => files(directory, '.php'))) {
  const source = fs.readFileSync(file, 'utf8');
  const marker = 'function __construct';
  const start = source.indexOf(marker);
  if (start < 0) continue;
  const bodyStart = source.indexOf('{', start);
  if (bodyStart < 0) continue;
  let depth = 0;
  let end = bodyStart;
  for (; end < source.length; ++end) {
    if (source[end] === '{') ++depth;
    if (source[end] === '}' && --depth === 0) break;
  }
  const body = source.slice(bodyStart, end + 1);
  if (/\b(file_get_contents|fopen|mkdir|flock|curl_exec|getCurrentRequest|createSchemaManager|fetchOne|executeStatement)\s*\(/.test(body)) {
    failures.push(`Constructor performs runtime I/O: ${path.relative(root, file)}`);
  }
}

const routes = read('src/Resources/config/routes.yaml');
const routeController = routes.match(/^\s*controller:\s*([^\s]+)\s*$/m)?.[1];
if (!routes.includes('/rest/api/v1/contao-multilingual-pagetree-license-updater')) failures.push('Updater route path is missing.');
if (!routeController || !classMap.has(routeController)) failures.push('Updater route controller does not exist.');
else if (!fs.readFileSync(classMap.get(routeController), 'utf8').includes('function __invoke(')) failures.push('Updater controller is not invokable.');

const bundleSchema = read('src/Schema/BundleSchema.php');
const ledgerDca = read('contao/dca/tl_multilingual_pagetree_channel_ledger.php');
const schemaListener = read('src/EventListener/BundleSchemaListener.php');
const channelMigration = read('src/Migration/ChannelLedgerMigration.php');
const integrityMigration = read('src/Migration/IntegrityIndexMigration.php');
const requiredIntegrityIndexes = [
  ['tl_article', 'clfmp_owner', ['cmpLanguage', 'cmpLanguageRoot']],
  ['tl_article_translation', 'clfmp_pid_lang', ['pid', 'language']],
  ['tl_article_translation', 'clfmp_review', ['reviewStatus']],
  ['tl_calendar_events_translation', 'clfmp_pid_lang', ['pid', 'language']],
  ['tl_calendar_events_translation', 'clfmp_review', ['reviewStatus']],
  ['tl_content', 'clfmp_owner', ['cmpLanguage', 'cmpLanguageRoot']],
  ['tl_content_translation', 'clfmp_pid_lang', ['pid', 'language']],
  ['tl_content_translation', 'clfmp_review', ['reviewStatus']],
  ['tl_faq_translation', 'clfmp_pid_lang', ['pid', 'language']],
  ['tl_faq_translation', 'clfmp_review', ['reviewStatus']],
  ['tl_inline_language', 'clfmp_root_lang', ['pid', 'language']],
  ['tl_inline_language', 'clfmp_lang_url', ['pid', 'urlDomain', 'urlEntryPoint']],
  ['tl_inline_language', 'clfmp_lang_host', ['urlDomain']],
  ['tl_news_translation', 'clfmp_pid_lang', ['pid', 'language']],
  ['tl_news_translation', 'clfmp_review', ['reviewStatus']],
  ['tl_page_translation', 'clfmp_pid_lang', ['pid', 'language']],
  ['tl_page_translation', 'clfmp_review', ['reviewStatus']],
];
for (const [table, name, columns] of requiredIntegrityIndexes) {
  const literal = `['table' => '${table}', 'name' => '${name}', 'columns' => [${columns.map(column => `'${column}'`).join(', ')}], 'unique' => false]`;
  if (!bundleSchema.includes(literal)) failures.push(`Authoritative schema is missing ${table}.${name} (${columns.join(', ')}).`);
}
for (const column of ['request_id', 'nonce_digest', 'fingerprint', 'result', 'document_version', 'claimed_at', 'completed_at']) {
  if (!bundleSchema.includes(`'${column}' => ['sql' =>`)) failures.push(`Ledger schema is missing column ${column}.`);
}
if (!ledgerDca.includes('BundleSchema::LEDGER_COLUMNS') || !ledgerDca.includes("'request_id' => 'primary'")) failures.push('Ledger DCA does not consume the authoritative schema contract.');
if (!schemaListener.includes('BundleSchema::namedIndexes()')) failures.push('Doctrine schema listener does not declare the named indexes.');
if (!services.includes('doctrine.event_listener, event: postGenerateSchema, priority: -100')) failures.push('Named schema listener is not registered after Contao DCA schema generation.');
if (!channelMigration.includes('BundleSchema::LEDGER_COLUMNS') || !channelMigration.includes('BundleSchema::LEDGER_INDEXES')) failures.push('Channel ledger migration has diverged from the schema contract.');
if (!integrityMigration.includes('BundleSchema::INTEGRITY_INDEXES')) failures.push('Integrity index migration has diverged from the schema contract.');
if (/DROP\s+(?:TABLE|INDEX)/i.test(channelMigration + integrityMigration)) failures.push('Bundle schema repair migrations contain a destructive DROP statement.');
if ((bundleSchema.match(/'name' => 'clfmp_/g) || []).length !== 17) failures.push('Authoritative schema must own exactly 17 clfmp_* indexes.');

const translationPolicyDca = read('src/Backend/TranslationPolicyDca.php');
const translationStateDca = read('src/Backend/TranslationStateDca.php');
if (!translationPolicyDca.includes("array_key_exists('sql', $definition)")) failures.push('Translation values are not restricted to source fields with physical SQL declarations.');
if (!translationStateDca.includes("'fieldStates' =") && !translationStateDca.includes("['fieldStates']")) failures.push('Canonical JSON translation-state storage is missing.');
if (!translationStateDca.includes('onbeforesubmit_callback') || !translationStateDca.includes('withoutVirtualStateFields')) failures.push('Virtual fieldState_* controls are not removed before persistence.');
if (/['"]fieldState_[A-Za-z0-9_]+['"]\s*=>\s*\[[\s\S]{0,300}['"]sql['"]/.test(translationStateDca)) failures.push('A virtual fieldState_* control declares a physical SQL column.');

const commandNames = [];
for (const file of files('src/Command', '.php')) {
  const source = fs.readFileSync(file, 'utf8');
  const name = source.match(/#\[AsCommand\(\s*name:\s*'([^']+)'/s)?.[1];
  if (!name) failures.push(`Command has no AsCommand name: ${path.relative(root, file)}`);
  else commandNames.push(name);
  if (source.includes('function __construct') && !source.includes('parent::__construct();')) failures.push(`Command constructor does not call its parent: ${path.relative(root, file)}`);
}
if (new Set(commandNames).size !== commandNames.length) failures.push('Duplicate console command name.');

for (const file of files('contao/dca', '.php')) {
  const source = fs.readFileSync(file, 'utf8');
  for (const match of source.matchAll(/\['(Vtinnovations\\ContaoMultilingualPagetree\\[^']+)',\s*'([^']+)'\]/g)) {
    const target = classMap.get(match[1]);
    if (!target) failures.push(`DCA callback class is missing: ${match[1]} in ${path.relative(root, file)}`);
    else if (!new RegExp(`function\\s+${match[2]}\\s*\\(`).test(fs.readFileSync(target, 'utf8'))) failures.push(`DCA callback method is missing: ${match[1]}::${match[2]}`);
  }
  for (const match of source.matchAll(/\['(tl_[a-z0-9_]+)',\s*'([^']+)'\]/g)) {
    if (!new RegExp(`class\\s+${match[1]}\\b`).test(source) || !new RegExp(`function\\s+${match[2]}\\s*\\(`).test(source)) failures.push(`Local DCA callback is missing: ${match[1]}::${match[2]} in ${path.relative(root, file)}`);
  }
}

const runtimeFiles = ['composer.json', ...files('src'), ...files('contao'), ...files('docs'), ...files('.github')];
const localDevelopmentPrefix = ['', 'Users', 'admin' + 'istrator'].join('/');
for (const item of runtimeFiles) {
  const file = path.isAbsolute(item) ? item : path.join(root, item);
  if (!fs.statSync(file).isFile()) continue;
  const source = fs.readFileSync(file, 'utf8');
  if (source.includes(localDevelopmentPrefix)) failures.push(`Absolute local path in ${path.relative(root, file)}.`);
  // The build-time material check may be referenced by documentation, package
  // scripts and CI, but never by runtime code other than the pinned material's
  // own explanatory comment.
  const relative = path.relative(root, file);
  const buildTimeReference = relative === 'composer.json' || relative.startsWith('.github/') || relative.startsWith('docs/') || file.endsWith('PinnedMaterial.php');
  if (source.includes('tools/check-release-material.php') && !buildTimeReference) failures.push(`Release material check connected to runtime: ${relative}.`);
}

// Undefined class constants are a fatal error that only surfaces when the line
// actually runs - a production cache warm-up, for example - so neither the PSR-4
// pass above nor a syntax check can see them. Every self::, static:: and
// ClassName:: constant reference is therefore resolved against the constants
// really declared in this project, following its own inheritance chain.
const blankOut = (source) => source
  .replace(/\/\*[\s\S]*?\*\//g, (m) => ' '.repeat(m.length))
  .replace(/\/\/[^\n]*/g, (m) => ' '.repeat(m.length))
  .replace(/(^|[^#])#[^\n[]*/g, (m) => m[0] + ' '.repeat(m.length - 1))
  .replace(/'(?:\\.|[^'\\])*'/g, (m) => ' '.repeat(m.length))
  .replace(/"(?:\\.|[^"\\])*"/g, (m) => ' '.repeat(m.length));

const declarations = new Map();
const phpSources = [...files('src', '.php'), ...files('tests', '.php')];

for (const file of phpSources) {
  const source = blankOut(fs.readFileSync(file, 'utf8'));
  const namespace = source.match(/^namespace\s+([^;]+);/m)?.[1]?.trim() ?? '';
  const imports = new Map();
  for (const match of source.matchAll(/^use\s+([^\s;(]+)(?:\s+as\s+(\w+))?\s*;/gm)) {
    const fq = match[1].replace(/^\\/, '');
    imports.set(match[2] ?? fq.split('\\').pop(), fq);
  }
  const declarationRe = /^\s*(?:final\s+|abstract\s+|readonly\s+)*(class|interface|trait|enum)\s+(\w+)(?:\s*:\s*\w+)?(?:\s+extends\s+([\w\\, ]+?))?(?:\s+implements\s+([\w\\, ]+?))?\s*\{/gm;
  for (const match of source.matchAll(declarationRe)) {
    const fqcn = namespace ? `${namespace}\\${match[2]}` : match[2];
    const body = source.slice(match.index + match[0].length);
    const constants = new Set();
    for (const c of body.matchAll(/^\s*(?:final\s+)?(?:public|protected|private)?\s*const\s+(?:\w+\s+)?(\w+)\s*=/gm)) constants.add(c[1]);
    if (match[1] === 'enum') for (const c of body.matchAll(/^\s*case\s+(\w+)\s*(?:=|;)/gm)) constants.add(c[1]);
    const parents = [match[3], match[4]].filter(Boolean).join(',').split(',')
      .map((name) => name.trim().replace(/^\\/, '')).filter(Boolean)
      .map((name) => imports.get(name) ?? (name.includes('\\') || !namespace ? name : `${namespace}\\${name}`));
    declarations.set(fqcn, { constants, parents, file, imports, namespace });
  }
}

const resolveConstants = (fqcn, seen = new Set()) => {
  if (seen.has(fqcn) || !declarations.has(fqcn)) return { constants: new Set(), external: !declarations.has(fqcn) };
  seen.add(fqcn);
  const entry = declarations.get(fqcn);
  const constants = new Set(entry.constants);
  let external = false;
  for (const parent of entry.parents) {
    const inherited = resolveConstants(parent, seen);
    for (const name of inherited.constants) constants.add(name);
    external = external || inherited.external;
  }
  return { constants, external };
};

const projectPrefix = 'Vtinnovations\\ContaoMultilingualPagetree\\';
for (const [fqcn, entry] of declarations) {
  if (!fqcn.startsWith(projectPrefix)) continue;
  const source = blankOut(fs.readFileSync(entry.file, 'utf8'));
  const own = resolveConstants(fqcn);
  for (const match of source.matchAll(/(?<![\w$])(\\?[A-Za-z_][\w\\]*)::(\w+)\b(?!\s*\()/g)) {
    const target = match[1].replace(/^\\/, '');
    const constant = match[2];
    if (constant === 'class' || !/^[A-Z][A-Za-z0-9_]*$/.test(constant)) continue;
    let resolved;
    if (target === 'self' || target === 'static') {
      resolved = own;
    } else if (target === 'parent') {
      if (!entry.parents.length) continue;
      resolved = resolveConstants(entry.parents[0]);
    } else {
      const targetFqcn = entry.imports.get(target)
        ?? (target.includes('\\') || !entry.namespace ? target : `${entry.namespace}\\${target}`);
      if (!targetFqcn.startsWith(projectPrefix)) continue;
      resolved = resolveConstants(targetFqcn);
    }
    if (!resolved.external && !resolved.constants.has(constant)) {
      const line = source.slice(0, match.index).split('\n').length;
      failures.push(`Undefined class constant ${target}::${constant} in ${path.relative(root, entry.file)}:${line}.`);
    }
  }
}

// DCA files are plain PHP scripts rather than classes, but they reference the
// same constants and enum cases - and they are executed during the very cache
// warm-up that surfaced this failure class - so their file-level references are
// resolved the same way.
for (const file of files('contao', '.php')) {
  const source = blankOut(fs.readFileSync(file, 'utf8'));
  const imports = new Map();
  for (const match of source.matchAll(/^use\s+([^\s;(]+)(?:\s+as\s+(\w+))?\s*;/gm)) {
    const fq = match[1].replace(/^\\/, '');
    imports.set(match[2] ?? fq.split('\\').pop(), fq);
  }
  for (const match of source.matchAll(/(?<![\w$])(\\?[A-Za-z_][\w\\]*)::(\w+)\b(?!\s*\()/g)) {
    const target = match[1].replace(/^\\/, '');
    const constant = match[2];
    if (constant === 'class' || !/^[A-Z][A-Za-z0-9_]*$/.test(constant)) continue;
    if (target === 'self' || target === 'static' || target === 'parent') continue;
    const targetFqcn = imports.get(target) ?? target;
    if (!targetFqcn.startsWith(projectPrefix)) continue;
    const resolved = resolveConstants(targetFqcn);
    if (!resolved.external && !resolved.constants.has(constant)) {
      const line = source.slice(0, match.index).split('\n').length;
      failures.push(`Undefined class constant ${target}::${constant} in ${path.relative(root, file)}:${line}.`);
    }
  }
}

if (failures.length) {
  console.error([...new Set(failures)].join('\n'));
  process.exit(1);
}

console.log(`Static installation audit passed: ${classMap.size} internal symbols, ${files('contao/dca', '.php').length} DCA files, ${declarations.size} class constant scopes.`);
