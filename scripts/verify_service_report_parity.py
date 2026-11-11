#!/usr/bin/env python3
"""Inspect golden-master visual parity of INTERNAL SERVICE REPORT fixture PDFs.
Requires PyMuPDF (`fitz`); optionally Pillow for side-by-side and overlay images.
Usage: python verify_service_report_parity.py REFERENCE.pdf GENERATED.pdf --out DIR
This is a measurement aid, not a replacement for human visual inspection.
"""
import argparse, json, re, sys
from pathlib import Path
try:
    import fitz
except ImportError:
    print('PyMuPDF missing. Install into a local isolated environment if permitted.', file=sys.stderr)
    raise SystemExit(2)

ANCHORS = {
    '01 GENERAL INFORMATION':118.2,
    '02 INSTRUMENT IDENTIFICATION':182.2,
    '03 ENVIRONMENT':288.6,
    '04 ACTIVITY':288.6,
    '05 STANDARD(S) USED':374.2,
    '06 TEST RESULT':517.2,
    '07 RESUME':699.2,
}
REQUIRED = [
    'PT. BENTOEL PRIMA','Cigarette Test Station','Sr. 3 Nr. 44',
    'Cleaning All Module','Verification','Leakage test',
    'Cleaning/replace vacuum filter','Cleaning/adjust internal reference',
    'ET4276','ET4614','ET4782','ET4260','ET4934',
    'Sodiline PD','99.0','198.1','293.5','394.9','487.0',
    'Correction Tolerance','Instrument working properly',
    'Electronically generated and approved. No wet signature is required.',
    'Page 1 of 1',
]

def spans(page):
    for b in page.get_text('dict')['blocks']:
        if b['type'] != 0: continue
        for line in b['lines']:
            for sp in line['spans']:
                if sp['text'].strip(): yield sp

def locate_anchor(page, label):
    n = lambda s: re.sub(r'\s+',' ',s).strip().upper()
    target = n(label)
    for block in page.get_text('dict')['blocks']:
        if block['type'] != 0:
            continue
        for line in block['lines']:
            line_spans = line['spans']
            line_text = ''.join(sp['text'] for sp in line_spans)
            if target in n(line_text):
                return round(min(sp['bbox'][1] for sp in line_spans if sp['text'].strip()), 1)
    return None

def inspect(doc):
    page=doc[0]
    fontnames=sorted({s['font'] for p in doc for s in spans(p)})
    images=[]
    for im in page.get_images(full=True):
        for rect in page.get_image_rects(im[0]):
            images.append([round(rect.x0,1),round(rect.y0,1),round(rect.x1,1),round(rect.y1,1)])
    text='\n'.join(p.get_text() for p in doc)
    norm=re.sub(r'\s+',' ',text)
    return {
        'page_count':len(doc),'page_size_pt':[round(page.rect.width,2),round(page.rect.height,2)],
        'fonts':fontnames,'image_bboxes_page1':images,
        'anchors_y':{k:locate_anchor(page,k) for k in ANCHORS},
        'missing_required_strings':[s for s in REQUIRED if s not in norm],
        'preview_disclaimer_present':'NOT AN ISSUED CERTIFICATE' in text,
    }

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('reference');parser.add_argument('generated')
    parser.add_argument('--out',default='parity_output')
    args=parser.parse_args();out=Path(args.out);out.mkdir(parents=True,exist_ok=True)
    with fitz.open(args.reference) as ref, fitz.open(args.generated) as gen:
        r,g=inspect(ref),inspect(gen)
        diffs={k:None if g['anchors_y'][k] is None else round(g['anchors_y'][k]-v,1) for k,v in ANCHORS.items()}
        failures=[]
        if g['page_count']!=1: failures.append('Golden fixture must render to exactly one page')
        if any(abs(x-y)>.1 for x,y in zip(g['page_size_pt'],[595.28,841.89])): failures.append('Incorrect A4 dimensions')
        for weight in ['NotoSans-Regular','NotoSans-SemiBold','NotoSans-Bold']:
            if not any(weight.lower() in f.lower() for f in g['fonts']): failures.append(f'Missing font {weight}')
        for k,delta in diffs.items():
            if delta is None: failures.append(f'Missing section {k}')
            elif abs(delta)>5: failures.append(f'Section {k} y offset {delta:+.1f} pt > 5 pt')
        if g['missing_required_strings']: failures.append('Missing fixture text: '+repr(g['missing_required_strings']))
        if g['preview_disclaimer_present']: failures.append('Preview disclaimer incorrectly copied')
        if not any(abs(b[0]-35.2)<4 and abs(b[1]-42)<4 and abs(b[2]-135.8)<4 and abs(b[3]-91)<4 for b in g['image_bboxes_page1']):
            failures.append('Logo image bounding box differs from reference')
        data={'reference':r,'generated':g,'anchor_deltas_pt':diffs,'failures':failures,'passes':not failures}
        (out/'parity_metrics.json').write_text(json.dumps(data,indent=2,ensure_ascii=False),encoding='utf-8')
        try:
            from PIL import Image,ImageChops,ImageEnhance,ImageDraw
            imgs=[]
            for label,p in [('REFERENCE',ref[0]),('GENERATED',gen[0])]:
                pix=p.get_pixmap(matrix=fitz.Matrix(2,2),alpha=False)
                img=Image.frombytes('RGB',(pix.width,pix.height),pix.samples)
                img.save(out/(label.lower()+'.png'))
                imgs.append(img)
            side=Image.new('RGB',(imgs[0].width*2+30,max(i.height for i in imgs)+40),'#e5e7eb')
            for i,img in enumerate(imgs): side.paste(img,(10+i*(img.width+10),30))
            d=ImageDraw.Draw(side);d.text((10,10),'REFERENCE',fill='black');d.text((20+imgs[0].width,10),'GENERATED',fill='black')
            side.save(out/'side_by_side.png')
            overlay=Image.blend(imgs[0],imgs[1],0.5)
            overlay.save(out/'overlay.png')
            # Advisory pixel diff; reference preview disclaimer creates intentional mismatch.
            diff=ImageEnhance.Contrast(ImageChops.difference(imgs[0],imgs[1])).enhance(3)
            diff.save(out/'difference.png')
        except ImportError:
            print('Pillow not available; skipping visual montage files.')
        print(json.dumps({'passes':not failures,'anchor_deltas_pt':diffs,'fonts':g['fonts'],'page_count':g['page_count'],'failures':failures},indent=2,ensure_ascii=False))
        raise SystemExit(0 if not failures else 1)
if __name__=='__main__': main()
