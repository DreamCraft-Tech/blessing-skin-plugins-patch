#!/usr/bin/env python3
"""Publish validated runtime artifacts as an atomic draft-to-public GitHub release."""
import argparse,hashlib,json,os,re,subprocess,sys
from datetime import datetime,timezone
from pathlib import Path

def gh(*args, optional=False):
    p=subprocess.run(['gh',*args],text=True,capture_output=True)
    if p.returncode:
        if optional and ('HTTP 404' in p.stderr or 'release not found' in p.stderr.lower()):return None
        raise RuntimeError(p.stderr or p.stdout)
    return p.stdout

def validate_assets(directory):
    files={p.name:p for p in directory.iterdir() if p.is_file()}
    data=json.loads(files['manifest.json'].read_text())
    expected={row['filename'] for row in data['packages']}
    if not expected or set(files)!=(expected|{'manifest.json','SHA256SUMS'}):raise ValueError('Release asset list does not match manifest')
    checks={}
    for line in files['SHA256SUMS'].read_text().splitlines():
        checksum,name=line.split('  ',1);checks[name]=checksum
    if set(checks)!=expected|{'manifest.json'}:raise ValueError('Incomplete checksums')
    for name,checksum in checks.items():
        if hashlib.sha256(files[name].read_bytes()).hexdigest()!=checksum:raise ValueError('Checksum mismatch: '+name)
    if len(expected)!=len(data['packages']):raise ValueError('Duplicate packages')
    for row in data['packages']:
        if checks[row['filename']]!=row['sha256']:raise ValueError('Manifest hash mismatch')
    return data,files

def publish(directory,kind):
    data,files=validate_assets(directory)
    repo=os.environ['GITHUB_REPOSITORY'];sha=os.environ['GITHUB_SHA']
    if data['commit']!=sha:raise ValueError('Artifacts are from a different commit')
    if kind=='server':
        if len(data['packages'])!=1:raise ValueError('Server release requires one complete runtime ZIP')
        version=data['version']
        if not re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+(?:[._+-][A-Za-z0-9.-]+)?',version):raise ValueError('Invalid version')
        tag='v'+version;title='Blessing Skin '+version+' · PHP 8.5'
    else:
        current=gh('release','list','--repo',repo,'--limit','100','--json','tagName,isDraft,isPrerelease')
        previous=next((r for r in json.loads(current) if not r['isDraft'] and not r['isPrerelease'] and r['tagName'].startswith('plugins-')),None)
        if previous and os.environ.get('FORCE_RELEASE')!='true':
            body=json.loads(gh('release','view',previous['tagName'],'--repo',repo,'--json','body'))['body']
            if 'Catalog-SHA256: '+data['catalog_sha256'] in body:
                print('Plugin versions unchanged; release skipped.');return
        tag='plugins-'+datetime.now(timezone.utc).strftime('%Y%m%d')+'-'+os.environ['GITHUB_RUN_NUMBER']
        title='PHP 8.5 插件 · '+str(len(data['packages']))+' 个独立安装包'
    existing=gh('release','view',tag,'--repo',repo,'--json','isDraft,targetCommitish',optional=True)
    if existing:
        existing=json.loads(existing)
        if not existing['isDraft']:
            print('Version already published; keeping existing release: '+tag);return
        if existing['targetCommitish']!=sha:raise ValueError('Existing draft belongs to another commit')
    notes=directory.parent/'release-notes.md'
    if kind=='server':
        body='完整 PHP 8.5 运行包，包含生产 Composer 依赖与已构建前端，可在站点“检查更新”页面上传升级。\n\n'
    else:
        body='每个 ZIP 对应一个可单独安装的插件，按站点需要选择下载。\n\n| 插件 | 版本 | 安装包 |\n| --- | --- | --- |\n'
        for row in data['packages']:
            body+=f"| {row['name']} | {row['version']} | [{row['filename']}](https://github.com/{repo}/releases/download/{tag}/{row['filename']}) |\n"
        body+='\nCatalog-SHA256: '+data['catalog_sha256']+'\n'
    body+='\n源码提交：`'+sha+'`。文件校验值见 SHA256SUMS，版本清单见 manifest.json。\n'
    notes.write_text(body)
    if not existing:gh('release','create',tag,'--repo',repo,'--target',sha,'--title',title,'--notes-file',str(notes),'--draft')
    gh('release','upload',tag,'--repo',repo,'--clobber',*[str(files[name]) for name in sorted(files)])
    uploaded=json.loads(gh('release','view',tag,'--repo',repo,'--json','assets'))['assets']
    actual={a['name']:a['size'] for a in uploaded}
    if set(actual)!=set(files) or any(actual[name]!=p.stat().st_size for name,p in files.items()):raise ValueError('Release upload is incomplete; draft retained')
    gh('release','edit',tag,'--repo',repo,'--draft=false','--latest')
    print('Published https://github.com/'+repo+'/releases/tag/'+tag)

if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--assets',type=Path,required=True);p.add_argument('--kind',choices=['server','plugins'],required=True);a=p.parse_args()
    publish(a.assets,a.kind)
