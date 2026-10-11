#!/usr/bin/env python3
"""No paid calls; use the source test runtime in local Docker bt and require explicit PASS evidence."""
from pathlib import Path
import datetime, json, re, subprocess, sys
root=Path(__file__).resolve().parents[2]
tests="policy registry contracts database lifecycle concurrent-admission request-context upload-identity transport stream-response response-replay safe-http material-fetch text-price music-price text-recovery composite-task schema documents directory protocol-errors usage image-price-binding tool-budget tool-availability video-duration result-assets submission-recovery tool-query publication webhooks preview worker-recovery recovery-lease install-fresh".split()
base=["docker","exec","-w","/www/wwwroot/likeadmin_aigc_saas/server","bt","/www/server/php/82/bin/php"]
report={"generated_at":datetime.datetime.now(datetime.timezone.utc).isoformat(),"commit":subprocess.check_output(["git","rev-parse","HEAD"],cwd=root,text=True).strip(),"paid_calls":False,"tests":[]}
for name in tests:
    result=subprocess.run(base+["tests/open-platform/"+name+".php"],capture_output=True,text=True,timeout=180)
    output=result.stdout+result.stderr
    ok=result.returncode==0 and bool(re.search(r"^PASS\b",output,re.M)) and not re.search(r"Exception trace:|Fatal error|Parse error|Uncaught",output)
    report["tests"].append({"name":name,"passed":ok,"exit_code":result.returncode,"output":output.strip()})
    print(("PASS " if ok else "FAIL ")+name,flush=True)
    if not ok: print(output,flush=True)
files=subprocess.check_output(["git","diff","--name-only","origin/develop...HEAD","--","*.php"],cwd=root,text=True).splitlines()
syntax=[]
for file in files:
    if not (root/file).is_file(): continue
    result=subprocess.run(base+["-l",file],capture_output=True,text=True,timeout=20)
    syntax.append({"file":file,"passed":result.returncode==0 and "No syntax errors detected" in result.stdout})
report["syntax"]=syntax
report["passed"]=all(test["passed"] for test in report["tests"]+syntax)
dir=root/"runtime/open-platform-acceptance";dir.mkdir(exist_ok=True)
path=dir/"regression-20261011.json";path.write_text(json.dumps(report,ensure_ascii=False,indent=2)+"\n")
print(json.dumps({"passed":report["passed"],"tests":len(report["tests"]),"syntax_files":len(syntax),"report":str(path)}))
sys.exit(0 if report["passed"] else 1)
