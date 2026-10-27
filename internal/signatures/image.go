package signatures

import (
	"bytes"
	"errors"
	"image"
	"image/color"
	"image/draw"
	_ "image/jpeg"
	"image/png"
	"math"
)

// The GD part of Signatures::clean(). PNG and JPEG are read with the standard library; WebP cannot be decoded
// without golang.org/x/image, so a WebP upload is answered "This image could not be read."

func imageSize(data []byte) (int, int, bool) {
	cfg, _, err := image.DecodeConfig(bytes.NewReader(data))
	if err != nil {
		return 0, 0, false
	}
	return cfg.Width, cfg.Height, true
}

func decodeImage(data []byte) (image.Image, error) {
	img, _, err := image.Decode(bytes.NewReader(data))
	if err != nil {
		return nil, err
	}
	if img.Bounds().Empty() {
		return nil, errors.New("empty image")
	}
	return img, nil
}

// resample is imagecopyresampled onto a transparent canvas: every target pixel is the area-weighted average of the
// source pixels under it (colours weighted by their opacity, like GD).
func resample(src *image.NRGBA, sx, sy, sw, sh, ow, oh int) *image.NRGBA {
	out := image.NewNRGBA(image.Rect(0, 0, ow, oh))
	kx := float64(sw) / float64(ow)
	ky := float64(sh) / float64(oh)
	for y := 0; y < oh; y++ {
		y0 := float64(y) * ky
		y1 := y0 + ky
		for x := 0; x < ow; x++ {
			x0 := float64(x) * kx
			x1 := x0 + kx
			var r, g, b, a, area float64
			for yy := int(y0); float64(yy) < y1 && yy < sh; yy++ {
				hy := math.Min(y1, float64(yy+1)) - math.Max(y0, float64(yy))
				if hy <= 0 {
					continue
				}
				for xx := int(x0); float64(xx) < x1 && xx < sw; xx++ {
					wx := math.Min(x1, float64(xx+1)) - math.Max(x0, float64(xx))
					if wx <= 0 {
						continue
					}
					w := wx * hy
					i := src.PixOffset(sx+xx, sy+yy)
					pa := float64(src.Pix[i+3]) / 255 * w
					r += float64(src.Pix[i]) * pa
					g += float64(src.Pix[i+1]) * pa
					b += float64(src.Pix[i+2]) * pa
					a += pa
					area += w
				}
			}
			j := out.PixOffset(x, y)
			if a > 0 {
				out.Pix[j] = uint8(math.Round(r / a))
				out.Pix[j+1] = uint8(math.Round(g / a))
				out.Pix[j+2] = uint8(math.Round(b / a))
				out.Pix[j+3] = uint8(math.Round(a / area * 255))
			} else { // GD's canvas: transparent white
				out.Pix[j], out.Pix[j+1], out.Pix[j+2] = 255, 255, 255
			}
		}
	}
	return out
}

// gdAlpha is GD's 0 (opaque) … 127 (transparent) scale.
func gdAlpha(a uint8) int { return 127 - int(math.Round(float64(a)*127/255)) }

// clean re-draws as a transparent PNG trimmed to the ink, at most 1200 × 500 px. paper: a photo or scan, so light
// pixels become transparent; a drawn pad image keeps its own transparency. nil = no ink found.
func clean(srcImg image.Image, paper bool) []byte {
	b := srcImg.Bounds()
	src := image.NewNRGBA(image.Rect(0, 0, b.Dx(), b.Dy()))
	draw.Draw(src, src.Bounds(), srcImg, b.Min, draw.Src)
	// work on at most 1600 px (a phone photo is far bigger than a signature needs)
	sw, sh := b.Dx(), b.Dy()
	k := math.Min(1.0, 1600/float64(max(sw, sh)))
	w := max(1, int(math.Round(float64(sw)*k)))
	h := max(1, int(math.Round(float64(sh)*k)))
	img := resample(src, 0, 0, sw, sh, w, h)

	// ink = pixels dark enough and not transparent; on paper, everything light turns transparent
	minX, minY, maxX, maxY := w, h, -1, -1
	for y := 0; y < h; y++ {
		for x := 0; x < w; x++ {
			i := img.PixOffset(x, y)
			r, g, bl := img.Pix[i], img.Pix[i+1], img.Pix[i+2]
			a := gdAlpha(img.Pix[i+3])
			lum := 0.299*float64(r) + 0.587*float64(g) + 0.114*float64(bl)
			if paper {
				if lum > 200 {
					img.SetNRGBA(x, y, color.NRGBA{255, 255, 255, 0})
					continue
				}
				if lum > 140 { // soft edge: partly transparent
					alpha := int(math.Round((lum - 140) / 60 * 127))
					img.SetNRGBA(x, y, color.NRGBA{r, g, bl, uint8(math.Round(float64(127-alpha) * 255 / 127))})
				}
			} else if a >= 120 {
				continue
			}
			minX, maxX = min(minX, x), max(maxX, x)
			minY, maxY = min(minY, y), max(maxY, y)
		}
	}
	if maxX < 0 {
		return nil
	}
	pad := 6
	minX, minY = max(0, minX-pad), max(0, minY-pad)
	cw := min(w, maxX+pad+1) - minX
	ch := min(h, maxY+pad+1) - minY
	scale := math.Min(1.0, math.Min(1200/float64(cw), 500/float64(ch)))
	ow := max(1, int(math.Round(float64(cw)*scale)))
	oh := max(1, int(math.Round(float64(ch)*scale)))
	out := resample(img, minX, minY, cw, ch, ow, oh)
	var buf bytes.Buffer
	enc := png.Encoder{CompressionLevel: png.BestCompression}
	if enc.Encode(&buf, out) != nil {
		return nil
	}
	return buf.Bytes()
}
