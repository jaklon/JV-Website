"""Generate Home Screen icons and the vector-based JV monogram favicon fallbacks."""
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont

root = Path(__file__).resolve().parents[1]
output = root / 'public/icons'
output.mkdir(exist_ok=True)
scale = 4
image = Image.new('RGB', (512 * scale, 512 * scale), '#faf7f2')
draw = ImageDraw.Draw(image)

def bezier(start, control1, control2, end):
    return [((1-t)**3 * start[0] + 3*(1-t)**2*t*control1[0] + 3*(1-t)*t*t*control2[0] + t**3*end[0],
             (1-t)**3 * start[1] + 3*(1-t)**2*t*control1[1] + 3*(1-t)*t*t*control2[1] + t**3*end[1])
            for t in (step/50 for step in range(51))]

# Established Home Screen heart, contained inside the maskable safe circle.
points = (bezier((32,48),(32,48),(12,36),(12,23))
          + bezier((12,23),(12,11),(28,8),(32,19))
          + bezier((32,19),(36,8),(52,11),(52,23))
          + bezier((52,23),(52,36),(32,48),(32,48)))
draw.polygon([((x*5+96)*scale,(y*5+38)*scale) for x,y in points], fill='#965c50')
font_path = Path('C:/Windows/Fonts/georgia.ttf')
font = ImageFont.truetype(str(font_path), 100*scale) if font_path.exists() else ImageFont.load_default(size=100*scale)
draw.text((256*scale,324*scale),'JV',font=font,anchor='mm',fill='#3d332e')
for filename, size in [('apple-touch-icon.png',180),('icon-192.png',192),('icon-512.png',512),('icon-maskable-512.png',512)]:
    image.resize((size,size),Image.Resampling.LANCZOS).save(output / filename,optimize=True)
print('Generated four opaque JV Story Home Screen icons.')

# Mirror favicon.svg using geometry, so the small monogram needs no installed font.
favicon_scale = 8
favicon = Image.new('RGBA', (64*favicon_scale,64*favicon_scale), (0,0,0,0))
favicon_draw = ImageDraw.Draw(favicon)
favicon_draw.rounded_rectangle((0,0,64*favicon_scale-1,64*favicon_scale-1),radius=14*favicon_scale,fill='#faf7f2')
heart = (bezier((32,57),(26,52),(5,36),(5,22)) + bezier((5,22),(5,7),(23,6),(32,19))
         + bezier((32,19),(41,6),(59,7),(59,22)) + bezier((59,22),(59,36),(38,52),(32,57)))
def polygon(points, fill):
    favicon_draw.polygon([(x*favicon_scale,y*favicon_scale) for x,y in points],fill=fill)
polygon(heart,'#965c50')
letter_j = [(19,25),(28,25),(28,35)] + bezier((28,35),(28,44),(15,44),(15,34)) + [(19,34)] + bezier((19,34),(19,39),(24,39),(24,35)) + [(24,29),(19,29)]
polygon(letter_j,'#faf7f2')
polygon([(31,25),(36,25),(40,36),(44,25),(49,25),(43,42),(37,42)],'#faf7f2')
for size in [16,32,48]:
    favicon.resize((size,size),Image.Resampling.LANCZOS).save(root / f'public/favicon-{size}x{size}.png',optimize=True)
favicon.resize((64,64),Image.Resampling.LANCZOS).save(root / 'public/favicon.ico',format='ICO',sizes=[(16,16),(32,32),(48,48)])
print('Generated JV Story favicon PNGs (16/32/48) and multi-size ICO.')
