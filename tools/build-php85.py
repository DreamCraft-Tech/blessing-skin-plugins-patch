#!/usr/bin/env python3
"""Rebuild the PHP 8.5 plugin packages from the supplied dependency locks."""
from pathlib import Path
import argparse,json,shutil,subprocess,zipfile
p=argparse.ArgumentParser()
p.add_argument('--php',default='php')
p.add_argument('--composer',default='composer')
p.add_argument('--pnpm',default='pnpm')
p.add_argument('--output',default='build-php85')
a=p.parse_args()
repo=Path(__file__).resolve().parents[1]
out=Path(a.output).resolve()
if out.exists():raise SystemExit('Use a new output directory; it must not already exist.')
php=shutil.which(a.php) or str(Path(a.php).resolve())
composer=shutil.which(a.composer) or str(Path(a.composer).resolve())
def run(cmd,cwd=repo):subprocess.run(cmd,cwd=cwd,check=True)
run([php,'-r','exit(PHP_VERSION_ID >= 80500 && PHP_VERSION_ID < 80600 ? 0 : 1);'])
run([a.pnpm,'install','--frozen-lockfile'])
run([a.pnpm,'build'])
run([a.pnpm,'lint'])
run([a.pnpm,'exec','jest','--runInBand'])
stage=out/'plugins'
shutil.copytree(repo/'plugins',stage,ignore=shutil.ignore_patterns('node_modules','vendor'))
for recipe in sorted((repo/'build-locks').glob('*/composer.lock')):
 target=stage/recipe.parent.name
 shutil.copy2(recipe,target/'composer.lock')
 shutil.copy2(recipe.with_name('composer.json'),target/'composer.json')
 run([php,composer,'install','--no-dev','--no-scripts','--no-interaction','--prefer-dist','--optimize-autoloader'],target)
for name in ['wecom-login','login-history','two-factor','cdn-real-ip','daily-luck']:
 target=stage/name
 if (target/'composer.json').exists():
  run([php,composer,'install','--no-dev','--no-scripts','--no-interaction','--prefer-dist','--optimize-autoloader'],target)
run(['patch','-p1','-i',str(repo/'patches/php85/qiniu-php-sdk.patch')],stage/'textures-qiniu-storage/vendor/qiniu/php-sdk')
for file in stage.rglob('*'):
 if 'vendor' in file.parts:continue
 if file.is_file() and 'tests' in file.relative_to(stage).parts:file.unlink();continue
 if file.is_file() and (file.suffix in {'.ts','.tsx','.svelte','.scss'} or file.name in {'.gitignore','phpunit.xml'}):file.unlink()
packages=out/'packages';packages.mkdir()
for manifest in sorted(stage.glob('*/package.json')):
 plugin=manifest.parent
 for file in plugin.rglob('*.php'):run([php,'-l',str(file)])
 if (plugin/'composer.json').exists():run([php,composer,'check-platform-reqs','--no-dev'],plugin)
 data=json.loads(manifest.read_text());version=data['version'].split('+')[0]
 with zipfile.ZipFile(packages/f"{data['name']}_{version}_php85.zip",'w',zipfile.ZIP_DEFLATED) as z:
  for file in sorted(plugin.rglob('*')):z.write(file,file.relative_to(stage))
print(packages)
