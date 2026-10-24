import importlib.util,json,hashlib,os,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
spec=importlib.util.spec_from_file_location('release',Path(__file__).resolve().parents[1]/'publish-release.py');release=importlib.util.module_from_spec(spec);spec.loader.exec_module(release)
class ReleaseTest(unittest.TestCase):
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory();self.root=Path(self.tmp.name)/'assets';self.root.mkdir();self.calls=[]
  self.env=patch.dict(os.environ,{'GITHUB_REPOSITORY':'DreamCraft-Tech/test','GITHUB_SHA':'a'*40,'GITHUB_RUN_NUMBER':'42','FORCE_RELEASE':'false'});self.env.start()
 def tearDown(self):self.env.stop();self.tmp.cleanup()
 def assets(self,kind='server'):
  rows=[]
  for name in (['server'] if kind=='server' else ['one','two']):
   f=self.root/(name+'.zip');f.write_bytes(b'package');rows.append({'name':name,'version':'1.0.0','filename':f.name,'sha256':hashlib.sha256(f.read_bytes()).hexdigest()})
  data={'version':'1.0.0','commit':'a'*40,'catalog_sha256':'catalog-test','packages':rows};(self.root/'manifest.json').write_text(json.dumps(data))
  (self.root/'SHA256SUMS').write_text(''.join(hashlib.sha256(f.read_bytes()).hexdigest()+'  '+f.name+'\n' for f in self.root.iterdir() if f.name!='SHA256SUMS'))
  return data
 def fake(self,*args,**kw):
  self.calls.append(args)
  if args[1]=='list':return '[]'
  if args[1]=='view':
   if args[-1]=='assets':return json.dumps({'assets':[{'name':f.name,'size':f.stat().st_size} for f in self.root.iterdir()]})
   return None
  return ''
 def test_publishes_only_after_upload_verification(self):
  self.assets()
  with patch.object(release,'gh',side_effect=self.fake):release.publish(self.root,'server')
  verbs=[x[1] for x in self.calls];self.assertLess(verbs.index('create'),verbs.index('upload'));self.assertLess(verbs.index('upload'),verbs.index('edit'));self.assertIn('--draft',self.calls[1])
  create=next(x for x in self.calls if x[1]=='create');self.assertEqual(create[create.index('--title')+1],'Release 1.0.0')
 def test_bad_checksum_and_missing_asset_stop_before_github(self):
  self.assets();(self.root/'server.zip').write_bytes(b'tampered')
  with patch.object(release,'gh') as gh:
   with self.assertRaises(ValueError):release.publish(self.root,'server')
   gh.assert_not_called()
  (self.root/'server.zip').unlink()
  with self.assertRaises(ValueError):release.validate_assets(self.root)
 def test_incomplete_upload_remains_draft(self):
  self.assets()
  def fake(*args,**kw):
   if args[-1]=='assets':self.calls.append(args);return '{"assets":[]}'
   return self.fake(*args,**kw)
  with patch.object(release,'gh',side_effect=fake):
   with self.assertRaises(ValueError):release.publish(self.root,'server')
  self.assertNotIn('edit',[x[1] for x in self.calls])
 def test_existing_public_version_is_immutable(self):
  self.assets()
  with patch.object(release,'gh',return_value='{"isDraft":false,"targetCommitish":"old"}') as gh:release.publish(self.root,'server');self.assertEqual(gh.call_count,2);self.assertEqual(gh.call_args.args,('release','edit','v1.0.0','--repo','DreamCraft-Tech/test','--title','Release 1.0.0'))
 def test_unchanged_plugin_catalog_skips_release(self):
  self.assets('plugins')
  def fake(*args,**kw):return '[{"tagName":"plugins-old","isDraft":false,"isPrerelease":false}]' if args[1]=='list' else '{"body":"Catalog-SHA256: catalog-test"}'
  with patch.object(release,'gh',side_effect=fake) as gh:release.publish(self.root,'plugins');self.assertEqual(gh.call_count,2)
 def test_plugins_upload_every_individual_package_without_bundle(self):
  self.assets('plugins')
  with patch.object(release,'gh',side_effect=self.fake):release.publish(self.root,'plugins')
  call=next(x for x in self.calls if x[1]=='upload');self.assertTrue(any(x.endswith('one.zip') for x in call));self.assertTrue(any(x.endswith('two.zip') for x in call));self.assertEqual(len([x for x in call if x.endswith('.zip')]),2)
 def test_artifact_commit_must_match_run(self):
  self.assets()
  with patch.dict(os.environ,{'GITHUB_SHA':'b'*40}),patch.object(release,'gh') as gh:
   with self.assertRaises(ValueError):release.publish(self.root,'server')
   gh.assert_not_called()
 def test_release_date_uses_gmt8_at_day_boundary(self):
  from datetime import datetime,timezone
  self.assertEqual(release.release_date(datetime(2026,10,24,16,1,tzinfo=timezone.utc)),'20261025')
  self.assertEqual(release.release_date(datetime(2026,10,24,15,59,tzinfo=timezone.utc)),'20261024')
 def test_plugin_names_increment_and_resume_own_draft(self):
  responses=[json.dumps({'isDraft':False,'targetCommitish':'old'}),json.dumps({'isDraft':True,'targetCommitish':'other'}),None]
  with patch.object(release,'release_date',return_value='20261024'),patch.object(release,'gh',side_effect=responses):
   self.assertEqual(release.plugin_release_name('repo','a'*40),('20261024-patch-3',None))
  own=json.dumps({'isDraft':True,'targetCommitish':'a'*40})
  with patch.object(release,'release_date',return_value='20261024'),patch.object(release,'gh',return_value=own):
   self.assertEqual(release.plugin_release_name('repo','a'*40),('20261024-patch',own))
 def test_plugin_title_matches_date_tag(self):
  self.assets('plugins')
  with patch.object(release,'release_date',return_value='20261024'),patch.object(release,'gh',side_effect=self.fake):release.publish(self.root,'plugins')
  create=next(x for x in self.calls if x[1]=='create');self.assertEqual(create[2],'20261024-patch');self.assertEqual(create[create.index('--title')+1],create[2])
 def test_new_plugin_tag_catalog_skips_duplicate_release(self):
  self.assets('plugins')
  def fake(*args,**kw):return '[{"tagName":"20261024-patch-2","isDraft":false,"isPrerelease":false}]' if args[1]=='list' else '{"body":"Catalog-SHA256: catalog-test"}'
  with patch.object(release,'gh',side_effect=fake) as gh:release.publish(self.root,'plugins');self.assertEqual(gh.call_count,2)
if __name__=='__main__':unittest.main()
