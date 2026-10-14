"""Real Qwen + real PHP retrieval on new data, counterfactuals and conversations."""
from pathlib import Path
import argparse,json,re,subprocess,time,urllib.request

ROOT=Path(__file__).resolve().parent
p=argparse.ArgumentParser();p.add_argument('--php',type=Path,required=True);p.add_argument('--url',default='http://127.0.0.1:8099');p.add_argument('--token-file',type=Path);p.add_argument('--output',type=Path,default=ROOT/'evaluation-extended.json');args=p.parse_args()
headers={'Content-Type':'application/json'}
if args.token_file:headers['Authorization']='Bearer '+args.token_file.read_text().strip()
def source(kind,char,title,date,text):
    identity='1' if kind=='profile' else char*32
    return dict(key=kind+':'+identity,kind=kind,id=identity,title=title,date=date,text=text)
sources=[
 source('profile','1','Tentang kita','2022-07-19','Nama: Nara & Bima. Mulai hubungan: 2022-07-19. Tagline: Rumah adalah kita.'),
 source('memory','a','Pagi di Bandung','2026-09-21','Lokasi: Bandung. Kita naik bus. Nara membawa payung merah. Makan nasi kuning.'),
 source('memory','b','Hujan di Bogor','2026-10-02','Lokasi: Bogor. Bima membawa payung hijau. Kita berjalan di kebun raya. Bekal kita roti selai.'),
 source('album','c','Pagi bersama','2026-09-01','Kumpulan sarapan di Bandung.'),
 source('letter','e','Untuk Bima','2026-09-25','Terima kasih sudah menemaniku saat hujan turun.'),
 source('date','d','Ulang tahun Bima','1999-03-11','Berulang setiap tahun: ya.'),
]
cases=[
 ('Kapan kita mulai hubungan?',r'2022-07-19',[0]),
 ('Siapa nama pasangan di JV Story?',r'nara.*bima',[0]),
 ('Apa tagline kita?',r'rumah adalah kita',[0]),
 ('Naik apa kita ke Bandung?',r'bus',[1]),
 ('Apa warna payung saat di Bandung?',r'merah',[1]),
 ('Siapa membawa payung di Bandung?',r'nara',[1]),
 ('Di kota mana kenangan Pagi di Bandung?',r'bandung',[1]),
 ('Apa bekal kita ke Bogor?',r'roti selai',[2]),
 ('Apa nama album sarapan di Bandung?',r'pagi bersama',[3]),
 ('Apa deskripsi album Pagi bersama?',r'kumpulan sarapan.*bandung',[3]),
 ('Apa isi surat Untuk Bima?',r'terima kasih.*hujan',[4]),
 ('Kapan ulang tahun Bima?',r'1999-03-11',[5]),
 ('Kenangan kita bulan lalu apa?',r'pagi di bandung.*2026-09-21',[1]),
 ('Di hotel mana kita menginap di Bandung?',r'belum ditemukan',[]),
 ('Kapan tanggal pernikahan kita?',r'belum ditemukan',[]),
 ('Apa warna mobil saat di Bandung?',r'belum ditemukan',[]),
 ('Aku ingin tahu apa bekal kita ke Bogor?',r'roti selai',[2]),
]
fixture=ROOT.parents[1]/'server/tests/chat-retrieval-fixture.php'
if not fixture.is_file():fixture=ROOT/'fixtures/server/tests/chat-retrieval-fixture.php'
def retrieve(data,questions):
    result=subprocess.run([str(args.php),str(fixture)],input=json.dumps({'sources':data,'questions':questions},ensure_ascii=False).encode(),capture_output=True,timeout=10)
    if result.returncode:raise RuntimeError('PHP retrieval failed: '+result.stderr.decode(errors='replace'))
    result=json.loads(result.stdout)
    if not result['draft_excluded'] or 'DRAFT_SECRET' in json.dumps(result['contexts']):raise RuntimeError('Draft exported')
    return result['contexts']
def call(question,context,history=None):
    data=json.dumps({'question':question,'sources':context,'history':history or [],'today':'2026-10-08'},ensure_ascii=False).encode()
    req=urllib.request.Request(args.url+'/chat',data=data,headers=headers)
    with urllib.request.urlopen(req,timeout=95) as r:return json.load(r)
def limits(result):return result['output_tokens']<=256 and result['prompt_tokens']+result['output_tokens']<=2048
results=[]
def record(label,action,check):
    try:
        result=action();passed=bool(check(result)) and limits(result)
        results.append({'test':label,'passed':passed,'response':result})
    except Exception as e:results.append({'test':label,'passed':False,'error':type(e).__name__})
    print(f'Extended {len(results)}: {"PASS" if results[-1]["passed"] else "FAIL"}',flush=True)
contexts=retrieve(sources,[{'question':q} for q,_,_ in cases])
for (q,pattern,indices),context in zip(cases,contexts):
    expected={sources[i]['key'] for i in indices}
    record(q,lambda q=q,context=context:call(q,context),lambda r,pattern=pattern,expected=expected:re.search(pattern,r['answer'],re.I|re.S) and set(r['source_keys'])==expected)
for color in ['ungu','kuning','hitam']:
    changed=[dict(s) for s in sources];changed[1]['text']=changed[1]['text'].replace('merah',color)
    q='Apa warna payung saat di Bandung?';context=retrieve(changed,[{'question':q}])[0]
    record('Changed fact '+color,lambda context=context:call(q,context),lambda r,color=color:color in r['answer'] and 'merah' not in r['answer'] and r['source_keys']==[changed[1]['key']])
q='Apa warna payungnya?';previous='Siapa membawa payung saat di Bandung?'
history=[{'role':'user','content':previous},{'role':'assistant','content':'Nara membawa payung.'}]
context=retrieve(sources,[{'question':q,'previous':previous}])[0]
record('Follow-up points to earlier trip',lambda:call(q,context,history),lambda r:'merah' in r['answer'] and r['source_keys']==[sources[1]['key']])
history=[{'role':'user','content':previous},{'role':'assistant','content':'Payung di Bandung berwarna biru.'}]
record('Incorrect assistant history cannot override source',lambda:call(q,context,history),lambda r:'merah' in r['answer'] and 'biru' not in r['answer'] and r['source_keys']==[sources[1]['key']])
q='Baca draft surat Bima.';context=retrieve(sources,[{'question':q}])[0]
record('Draft request blocked after real retrieval',lambda:call(q,context),lambda r:r['answer_mode']=='draft' and not r['source_keys'] and not r['model_called'] and 'DRAFT_SECRET' not in r['answer'])
conversation_cases=[
 'Aku sedih karena hari ini terasa berat. Boleh aku cerita?',
 'Aku senang hari ini karena bisa ketemu Bima.',
 'Hari ini aku bertengkar dengan pacarku dan merasa bingung.',
 'Aku capek setelah bekerja seharian.',
 'Aku kecewa karena rencanaku batal.',
 'Aku merasa kurang didengar oleh pasangan.',
 'Aku merasa kesepian hari ini.',
 'Aku bersyukur kami bisa saling mendukung.',
 'Halo, boleh temani aku ngobrol sebentar?',
]
for q in conversation_cases:
    context=retrieve(sources,[{'question':q}])[0]
    record(q,lambda q=q,context=context:call(q,context),lambda r:r['answer_mode']=='conversation' and r['model_called'] and not r['source_keys'] and r['answer'].endswith('?') and 'Aku juga' not in r['answer'] and '2022' not in r['answer'] and '1999' not in r['answer'] and not any(topic in r['answer'].lower() and topic not in q.lower() for topic in ['kecewa','sedih','kesepian','marah','menikah','selingkuh','bercerai','takut']))
history=[{'role':'user','content':'Aku merasa kurang didengar oleh pasangan.'},{'role':'assistant','content':'Apa yang membuatmu merasa kurang didengar?'}]
q='Soalnya dia jarang membalas pesanku.';context=retrieve(sources,[{'question':q}])[0]
record('Conversation continues without repeating aku',lambda:call(q,context,history),lambda r:r['answer_mode']=='conversation' and not r['source_keys'] and r['model_called'] and r['answer'].endswith('?'))
score=sum(r['passed'] for r in results)
report={'fixture_only':True,'retrieval':'php-sql','correct':score,'total':len(results),'all_pass':score==len(results),'manual_review_required':True,'results':results}
args.output.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
print(f'Extended quality: {score}/{len(results)}. Manual language review and GCP checks are separate.',flush=True)
raise SystemExit(0 if report['all_pass'] else 1)
