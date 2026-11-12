"""Host operator and task processes, exercised without Docker or production access."""
import fcntl
import gzip
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

REPO = Path(__file__).resolve().parents[2]
OLD = "sha256:" + "a" * 64
NEW = "sha256:" + "b" * 64
COMMIT = "1" * 40

DOCKER = r"""#!/usr/bin/env python3
import gzip,json,os,sys
from pathlib import Path
r=Path(os.environ["TEST_ROOT"])
a=sys.argv[1:]
with (r/"commands").open("a") as f: f.write(json.dumps(a)+"\n")
fail=os.environ.get("FAIL","")
if a[0]=="build":
    sys.exit(1 if fail=="build" else 0)
if a[:2]==["image","inspect"]:
    if "-f" not in a:
        sys.exit(1 if fail=="rollback_image" else 0)
    if ".Id" in a[3]: print("sha256:"+"b"*64)
    elif "revision" in a[3]: print(("0" if a[-1]=="sha256:"+"a"*64 else "1")*40)
    sys.exit(0)
if a[:2]==["container","inspect"]:
    sys.exit(1 if fail=="inspect" or not (r/"container").exists() else 0)
if a[:2]==["container","ls"]:
    if fail=="inspect":sys.exit(1)
    if (r/"container").exists():print("energy-cost-prd")
    sys.exit(0)
if a[0]=="inspect":
    pattern=a[2]
    if "Mounts" in pattern:
        pairs=[("config","/app/app/config"),("data/var","/app/var"),("data/logs","/app/logs"),("backups/manual","/app/backups")]
        mounts=[{"Type":"bind","Source":str(r/source),"Destination":target,"RW":source!="config"} for source,target in pairs]
        if fail=="wrong-mount":mounts[0]["Source"]=str(r/"other-config")
        print(json.dumps(mounts))
    elif "managed" in pattern: print("prd")
    elif "Running" in pattern: print("true" if (r/"running").exists() else "false")
    elif ".Image" in pattern: print((r/"container").read_text())
    elif "Health" in pattern: print("unhealthy" if fail=="health" else "healthy")
    sys.exit(0)
if a[0]=="run":
    if "--entrypoint" in a:
        if a[-1]=="app/scripts/migrate.php" and fail=="migration": sys.exit(1)
        sys.exit(0)
    if fail=="create":sys.exit(125)
    (r/"container").write_text(a[-1]); (r/"running").touch(); sys.exit(0)
if a[0]=="stop": (r/"running").unlink(missing_ok=True); sys.exit(0)
if a[0]=="start":
    if fail=="start":sys.exit(1)
    (r/"running").touch(); sys.exit(0)
if a[0]=="rm": (r/"container").unlink(missing_ok=True); sys.exit(0)
if a[0]=="exec":
    if "app/scripts/drain_scheduled_tasks.php" in a and fail=="drain":sys.exit(1)
    if "dbbackup" in a:
        if fail in ("backup","backup-health"):sys.exit(1)
        target=r/"dumps"/"mariadb"/"energyv3"/"fresh.sql.gz"
        with gzip.open(target,"wb") as f:f.write(b"-- dedicated test dump\nSELECT 1;\n")
    if "curl" in a and fail=="http":sys.exit(1)
    if "curl" in a and fail=="backup-health" and any("/healthz" in part for part in a):sys.exit(22)
    if "allowed_ips" in a[-1]:print("1" if fail=="restricted" else "0")
    if "curl" in a and "--fail" in a and fail=="restricted" and "/healthz" not in a[-1]:sys.exit(22)
    if "https://energy-cost.eu/login" in a:
        print("X-Energy-Cost-Revision: "+("1" if (r/"container").read_text()=="sha256:"+"b"*64 else "0")*40)
        if "-w" in a: print("403" if fail=="restricted" else "200")
    if "--publish" in a and fail=="discord":sys.exit(1)
    sys.exit(0)
sys.exit(0)
"""

GIT = r"""#!/usr/bin/env python3
import io,sys,tarfile
a=sys.argv[1:]
if "get-url" in a:print("https://github.com/Rayman223/energy-cost-management.git")
elif "rev-parse" in a:print("1"*40)
elif "archive" in a:
 with tarfile.open(fileobj=sys.stdout.buffer,mode="w|") as t:
  data=b"fixture\n"; info=tarfile.TarInfo("Dockerfile");info.size=len(data);t.addfile(info,io.BytesIO(data))
"""

class OperatorTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        for name in ("state", "config", "src/.git", "data/var", "data/logs", "backups/manual", "bin", "dumps/mariadb/energyv3"):
            (self.root/name).mkdir(parents=True,exist_ok=True)
        (self.root/"config/config.php").write_text("<?php return [];")
        (self.root/"config/runtime.env").write_text("EC_SCHEDULER_TZ=Europe/Brussels\n")
        (self.root/"container").write_text(OLD)
        (self.root/"running").touch()
        (self.root/"state/current.json").write_text(json.dumps({"image":OLD,"commit":"0"*40}))
        (self.root/"state/delivery.json").write_text(json.dumps({"phase":"complete","image":OLD,"commit":"0"*40}))
        for name,content in {
            "docker":DOCKER,"git":GIT,"mountpoint":"#!/bin/sh\nexit 0\n",
            "chown":"#!/bin/sh\nexit 0\n","sleep":"#!/bin/sh\n/bin/sleep .01\n",
        }.items():
            file=self.root/"bin"/name;file.write_text(content);file.chmod(0o755)
        self.env={**os.environ,"PATH":str(self.root/"bin")+os.pathsep+os.environ["PATH"],
                  "TEST_ROOT":str(self.root),"EC_ROOT":str(self.root),"EC_BACKUP_ROOT":str(self.root/"dumps"),
                  "EC_HEALTH_TIMEOUT":"1"}

    def tearDown(self):
        self.temp.cleanup()

    def run_operator(self,*args,fail=""):
        return subprocess.run(["bash",str(REPO/"deploy/unraid.sh"),*args],
                              env={**self.env,"FAIL":fail},capture_output=True,text=True,timeout=15)

    def commands(self):
        return [json.loads(line) for line in (self.root/"commands").read_text().splitlines()]

    def test_prepare_collision_does_not_touch_existing_service_or_volumes(self):
        (self.root/"state/current.json").unlink()
        (self.root/"state/delivery.json").unlink()
        result=self.run_operator("prepare")
        self.assertNotEqual(result.returncode,0)
        self.assertFalse((self.root/"data/var/maintenance").exists())
        self.assertFalse((self.root/"data/var/.ec-volume").exists())
        self.assertFalse((self.root/"state/delivery.json").exists())
        self.assertEqual((self.root/"container").read_text(),OLD)
        self.assertNotIn("build",[c[0] for c in self.commands()])

    def test_repeated_image_update_preserves_real_rollback_target(self):
        self.assertEqual(self.run_operator("update").returncode,0)
        self.assertEqual(self.run_operator("update").returncode,0)
        self.assertEqual(json.loads((self.root/"state/previous.json").read_text())["image"],OLD)

    def test_publication_resume_preserves_backup_and_previous_image_receipt(self):
        self.assertNotEqual(self.run_operator("update",fail="discord").returncode,0)
        before=json.loads((self.root/"state/delivery.json").read_text())
        self.assertTrue(before["backup"])
        self.assertTrue(before["previous_image"])
        self.assertEqual(self.run_operator("resume-announcements").returncode,0)
        after=json.loads((self.root/"state/delivery.json").read_text())
        self.assertEqual(after["backup"],before["backup"])
        self.assertEqual(after["previous_image"],before["previous_image"])

    def test_backup_restart_failure_can_resume_without_migration_or_publication(self):
        result=self.run_operator("backup",fail="start")
        self.assertNotEqual(result.returncode,0)
        self.assertTrue((self.root/"data/var/maintenance").exists())
        receipt=json.loads((self.root/"state/delivery.json").read_text())
        self.assertEqual(receipt["phase"],"backup_restarting")
        self.assertEqual(self.run_operator("resume-announcements").returncode,0)
        self.assertTrue((self.root/"running").exists())
        self.assertFalse((self.root/"data/var/maintenance").exists())
        self.assertFalse(any(c[-1:] == ["app/scripts/migrate.php"] or "--publish" in c for c in self.commands()))

    def test_missing_rollback_image_never_stops_the_service(self):
        (self.root/"state/previous.json").write_text(json.dumps({"image":NEW,"commit":COMMIT}))
        result=self.run_operator("rollback","--schema-compatible",fail="rollback_image")
        self.assertNotEqual(result.returncode,0)
        self.assertTrue((self.root/"running").exists())
        self.assertFalse((self.root/"data/var/maintenance").exists())
        self.assertNotIn("stop",[c[0] for c in self.commands()])

    def test_healthy_previous_image_can_be_restored_without_running_migrations(self):
        self.assertEqual(self.run_operator("update").returncode,0)
        result=self.run_operator("rollback","--schema-compatible")
        self.assertEqual(result.returncode,0,result.stdout+result.stderr)
        self.assertEqual((self.root/"container").read_text(),OLD)
        self.assertEqual(json.loads((self.root/"state/current.json").read_text())["image"],OLD)

    def test_configured_ip_restriction_does_not_turn_a_valid_deployment_into_failure(self):
        result=self.run_operator("update",fail="restricted")
        self.assertEqual(result.returncode,0,result.stdout+result.stderr)
        self.assertFalse((self.root/"data/var/maintenance").exists())

    def test_prepare_docker_read_error_never_assumes_container_absence(self):
        (self.root/"state/current.json").unlink()
        (self.root/"state/delivery.json").unlink()
        result=self.run_operator("prepare",fail="inspect")
        self.assertNotEqual(result.returncode,0)
        self.assertFalse((self.root/"data/var/maintenance").exists())
        self.assertNotIn("build",[c[0] for c in self.commands()])

    def test_backup_recovery_never_ignores_an_internal_health_failure(self):
        result=self.run_operator("update",fail="backup-health")
        self.assertNotEqual(result.returncode,0)
        self.assertTrue((self.root/"data/var/maintenance").exists())
        self.assertTrue((self.root/"data/var/cron-paused").exists())

    def test_resuming_failed_rollback_never_publishes_a_release(self):
        self.assertEqual(self.run_operator("update").returncode,0)
        self.assertNotEqual(self.run_operator("rollback","--schema-compatible",fail="health").returncode,0)
        before=sum("--publish" in c for c in self.commands())
        result=self.run_operator("resume-announcements")
        self.assertEqual(result.returncode,0,result.stdout+result.stderr)
        self.assertEqual(sum("--publish" in c for c in self.commands()),before)

    def test_failed_container_creation_can_resume_without_repeating_migrations(self):
        self.assertNotEqual(self.run_operator("update",fail="create").returncode,0)
        self.assertFalse((self.root/"container").exists())
        before=sum(c[-1:] == ["app/scripts/migrate.php"] for c in self.commands())
        result=self.run_operator("resume-announcements")
        self.assertEqual(result.returncode,0,result.stdout+result.stderr)
        self.assertEqual((self.root/"container").read_text(),NEW)
        self.assertEqual(sum(c[-1:] == ["app/scripts/migrate.php"] for c in self.commands()),before)

    def test_failed_container_creation_still_allows_explicit_compatible_rollback(self):
        self.assertNotEqual(self.run_operator("update",fail="create").returncode,0)
        result=self.run_operator("rollback","--schema-compatible")
        self.assertEqual(result.returncode,0,result.stdout+result.stderr)
        self.assertEqual((self.root/"container").read_text(),OLD)

    def test_wrong_existing_configuration_mount_is_rejected_before_any_delivery(self):
        result=self.run_operator("update",fail="wrong-mount")
        self.assertNotEqual(result.returncode,0)
        self.assertTrue((self.root/"running").exists())
        self.assertFalse((self.root/"data/var/maintenance").exists())
        self.assertNotIn("build",[c[0] for c in self.commands()])

    def test_build_failure_preserves_live_service(self):
        result=self.run_operator("update",fail="build")
        self.assertNotEqual(result.returncode,0)
        self.assertTrue((self.root/"running").exists())
        self.assertFalse((self.root/"data/var/maintenance").exists())
        self.assertNotIn("stop",[c[0] for c in self.commands()])

    def test_busy_task_drain_failure_retains_maintenance_without_stopping(self):
        result=self.run_operator("update",fail="drain")
        self.assertNotEqual(result.returncode,0)
        self.assertTrue((self.root/"running").exists())
        self.assertTrue((self.root/"data/var/maintenance").exists())
        self.assertNotIn("stop",[c[0] for c in self.commands()])

    def test_backup_failure_restores_previous_service_without_migrating(self):
        result=self.run_operator("update",fail="backup")
        self.assertNotEqual(result.returncode,0)
        self.assertTrue((self.root/"running").exists())
        self.assertFalse((self.root/"data/var/maintenance").exists())
        self.assertFalse(any(c[-1:] == ["app/scripts/migrate.php"] for c in self.commands()))
        self.assertEqual(json.loads((self.root/"state/delivery.json").read_text())["phase"],"complete")

    def test_migration_failure_keeps_maintenance_and_previous_image(self):
        result=self.run_operator("update",fail="migration")
        self.assertNotEqual(result.returncode,0,result.stdout+result.stderr)
        self.assertTrue((self.root/"data/var/maintenance").exists())
        self.assertFalse((self.root/"running").exists())
        self.assertEqual((self.root/"container").read_text(),OLD)
        self.assertFalse(any("--publish" in c for c in self.commands()))

    def test_health_failure_never_restarts_old_code_on_changed_schema(self):
        result=self.run_operator("update",fail="health")
        self.assertNotEqual(result.returncode,0)
        self.assertTrue((self.root/"data/var/maintenance").exists())
        self.assertEqual((self.root/"container").read_text(),NEW)
        self.assertEqual(json.loads((self.root/"state/previous.json").read_text())["image"],OLD)

    def test_success_backs_up_migrates_probes_then_publishes(self):
        result=self.run_operator("update")
        self.assertEqual(result.returncode,0,result.stdout+result.stderr)
        self.assertFalse((self.root/"data/var/maintenance").exists())
        self.assertFalse((self.root/"data/var/cron-paused").exists())
        self.assertEqual(json.loads((self.root/"state/current.json").read_text())["image"],NEW)
        commands=self.commands()
        migrate=next(i for i,c in enumerate(commands) if c[-1:] == ["app/scripts/migrate.php"])
        publish=next(i for i,c in enumerate(commands) if "--publish" in c)
        probe=next(i for i,c in enumerate(commands) if "https://energy-cost.eu/login" in c)
        self.assertLess(migrate,probe)
        self.assertLess(probe,publish)
        snapshots=list((self.root/"backups").glob("20*/SHA256SUMS"))
        self.assertEqual(len(snapshots),1)

    def test_http_failure_blocks_announcements_and_can_resume(self):
        result=self.run_operator("update",fail="http")
        self.assertNotEqual(result.returncode,0)
        self.assertTrue((self.root/"data/var/maintenance").exists())
        self.assertFalse(any("--publish" in c for c in self.commands()))
        before=sum(c[-1:] == ["app/scripts/migrate.php"] for c in self.commands())
        result=self.run_operator("resume-announcements")
        self.assertEqual(result.returncode,0,result.stdout+result.stderr)
        self.assertEqual(sum(c[-1:] == ["app/scripts/migrate.php"] for c in self.commands()),before)

    def test_publication_retry_does_not_repeat_migration(self):
        result=self.run_operator("update",fail="discord")
        self.assertNotEqual(result.returncode,0)
        self.assertEqual(json.loads((self.root/"state/delivery.json").read_text())["phase"],"server_healthy")
        before=sum(c[-1:] == ["app/scripts/migrate.php"] for c in self.commands())
        result=self.run_operator("resume-announcements")
        self.assertEqual(result.returncode,0,result.stdout+result.stderr)
        self.assertEqual(sum(c[-1:] == ["app/scripts/migrate.php"] for c in self.commands()),before)

    def test_rollback_requires_schema_confirmation(self):
        self.assertEqual(self.run_operator("rollback").returncode,2)
        self.assertNotIn("stop",[c[0] for c in self.commands()])

    def test_prepare_starts_in_maintenance_without_migrations_or_publication(self):
        (self.root/"container").unlink()
        (self.root/"state/delivery.json").unlink()
        (self.root/"state/current.json").unlink()
        result=self.run_operator("prepare")
        self.assertEqual(result.returncode,0,result.stdout+result.stderr)
        self.assertTrue((self.root/"data/var/maintenance").exists())
        self.assertTrue((self.root/"data/var/cron-paused").exists())
        self.assertFalse(any(c[-1:] == ["app/scripts/migrate.php"] or "--publish" in c for c in self.commands()))


class TaskProcessTest(unittest.TestCase):
    def test_pause_lock_error_and_independent_task(self):
        with tempfile.TemporaryDirectory() as temp:
            root=Path(temp)
            for name in ("var/scheduler","var/tmp","var/notifications","logs","app/scripts"):
                (root/name).mkdir(parents=True)
            (root/"app/bootstrap.php").write_text("<?php return [];")
            (root/"app/scripts/cron_dynamic_prices.php").write_text("<?php echo 'failure'; exit(1);")
            (root/"app/scripts/cron_load_profile_check.php").write_text("<?php echo '[MISSING] RLP0N BE 2026-10 0.0\\n'; exit(2);")
            code="require $argv[1].'/vendor/autoload.php'; exit((new App\\Service\\ScheduledTaskRunner($argv[2]))->run($argv[3]));"
            def task(job):
                return subprocess.run(["php","-r",code,str(REPO),str(root),job],capture_output=True,text=True,timeout=5)
            (root/"var/cron-paused").touch()
            self.assertEqual(task("dynamic-prices").returncode,75)
            (root/"var/cron-paused").unlink()
            drain_code="require $argv[1].'/vendor/autoload.php'; try { (new App\\Service\\ScheduledTaskRunner($argv[2]))->waitUntilIdle(0); } catch (RuntimeException) { exit(75); }"
            def drain():
                return subprocess.run(["php","-r",drain_code,str(REPO),str(root)],capture_output=True,timeout=5).returncode
            with (root/"var/scheduler/dynamic-prices.lock").open("w") as lock:
                fcntl.flock(lock,fcntl.LOCK_EX)
                self.assertEqual(task("dynamic-prices").returncode,75)
                self.assertEqual(drain(),75)
                result=task("load-profiles")
                self.assertEqual(result.returncode,2,result.stderr)
            self.assertEqual(drain(),0)
            result=task("dynamic-prices")
            self.assertEqual(result.returncode,1,result.stderr)
            self.assertTrue(json.loads((root/"var/notifications/dynamic-prices.json").read_text())["pending"])
            self.assertEqual(json.loads((root/"var/notifications/load-profiles.json").read_text())["kind"],"missing")


class TaskLoggingFailureTest(unittest.TestCase):
    def test_unwritable_log_does_not_skip_work_or_lose_profile_notification(self):
        with tempfile.TemporaryDirectory() as temp:
            root=Path(temp)
            for name in ("var/scheduler","var/tmp","var/notifications","logs","app/scripts"):
                (root/name).mkdir(parents=True)
            (root/"app/bootstrap.php").write_text("<?php return [];")
            (root/"app/scripts/cron_load_profile_check.php").write_text("<?php echo '[MISSING] RLP0N BE 2026-10 0.0\\n'; exit(2);")
            (root/"logs/load-profiles.log").symlink_to("/dev/full")
            code="require $argv[1].'/vendor/autoload.php'; exit((new App\\Service\\ScheduledTaskRunner($argv[2]))->run('load-profiles'));"
            result=subprocess.run(["php","-r",code,str(REPO),str(root)],capture_output=True,text=True,timeout=5)
            self.assertEqual(result.returncode,2,result.stderr)
            self.assertTrue(json.loads((root/"var/notifications/load-profiles.json").read_text())["pending"])


class NotificationRelayTest(unittest.TestCase):
    def test_send_failure_retries_and_invalid_payload_never_reaches_notify(self):
        with tempfile.TemporaryDirectory() as temp:
            root=Path(temp);(root/"state").mkdir();(root/"bin").mkdir()
            item={"job":"load-profiles","id":"a"*32,"kind":"missing","labels":["RLP0N/BE 2026-10"],"url":"https://energy-cost.eu"}
            queue=root/"queue";queue.write_text(json.dumps([item]))
            docker=root/"bin/docker"
            docker.write_text("#!/usr/bin/env python3\nimport os,sys\nfrom pathlib import Path\nr=Path(os.environ['TEST_ROOT'])\nif '--list' in sys.argv:print((r/'queue').read_text())\nelif '--ack' in sys.argv:(r/'ack').touch()\n")
            notify=root/"bin/notify"
            notify.write_text("#!/usr/bin/env python3\nimport os,sys,json\nfrom pathlib import Path\nPath(os.environ['TEST_ROOT'],'notify-args').write_text(json.dumps(sys.argv[1:]))\nsys.exit(int(os.environ.get('NOTIFY_FAIL','0')))\n")
            mount=root/"bin/mountpoint";mount.write_text("#!/bin/sh\nexit 0\n")
            for path in (docker,notify,mount):path.chmod(0o755)
            env={**os.environ,"PATH":str(root/"bin")+os.pathsep+os.environ["PATH"],"TEST_ROOT":str(root),"EC_ROOT":str(root),"NOTIFY_BIN":str(notify)}
            def relay(fail="0"):
                return subprocess.run(["bash",str(REPO/"deploy/notify-unraid.sh")],env={**env,"NOTIFY_FAIL":fail},capture_output=True,timeout=5)
            self.assertEqual(relay("1").returncode,1)
            self.assertFalse((root/"ack").exists())
            self.assertEqual(relay().returncode,0)
            self.assertTrue((root/"ack").exists())
            args=json.loads((root/"notify-args").read_text())
            self.assertIn("https://energy-cost.eu/admin/load-profiles",args)
            (root/"ack").unlink();(root/"notify-args").unlink()
            item["labels"]=["$(touch SHOULD_NEVER_RUN)"]
            queue.write_text(json.dumps([item]))
            self.assertNotEqual(relay().returncode,0)
            self.assertFalse((root/"notify-args").exists())
            self.assertFalse((root/"ack").exists())


if __name__=="__main__":
    unittest.main()
