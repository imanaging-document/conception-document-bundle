#!/usr/bin/env python3
"""Compare PDF text and physical geometry, independently of raster antialiasing.
Usage: python compare-pdf-layout.py reference.pdf candidate.pdf --tolerance-mm 0.5
Requires pdfplumber in the development/QA environment only.
"""
import argparse
import sys
import pdfplumber

parser=argparse.ArgumentParser()
parser.add_argument('reference')
parser.add_argument('candidate')
parser.add_argument('--tolerance-mm',type=float,default=.5)
args=parser.parse_args()
limit=args.tolerance_mm*72/25.4
errors=[]
with pdfplumber.open(args.reference) as reference, pdfplumber.open(args.candidate) as candidate:
    if len(reference.pages)!=len(candidate.pages): errors.append('Le nombre de pages a changé.')
    for number,(old,new) in enumerate(zip(reference.pages,candidate.pages),1):
        if any(abs(a-b)>limit for a,b in zip((old.width,old.height),(new.width,new.height))): errors.append(f'Page {number} : format différent.')
        old_words=old.extract_words();new_words=new.extract_words()
        if [word['text'] for word in old_words]!=[word['text'] for word in new_words]:
            errors.append(f'Page {number} : contenu ou coupures différents.');continue
        for index,(a,b) in enumerate(zip(old_words,new_words),1):
            if any(abs(a[key]-b[key])>limit for key in ['x0','x1','top','bottom']): errors.append(f'Page {number}, mot {index} : déplacement supérieur à la tolérance.')
for error in errors[:30]: print(error)
print(f'{len(errors)} écart(s). La comparaison géométrique complète la recette visuelle des images, formes et couleurs.')
sys.exit(bool(errors))
