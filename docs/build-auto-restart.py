"""README image for the auto-restart panel.

Same treatment as hero.png so the two read as one set: dark ground, amber rings
on the thing being described, a numbered badge on a corner, and the legend in two
columns underneath.

Built from a capture of the settings panel only. Nothing above or beside it is
included, so no server name, host or player is in the frame and none has to be
blurred out. Blurring leaves a recognisable shape and length anyway.

Usage:  python3 docs/build-auto-restart.py <screenshot.png>
"""
import os
import sys

from PIL import Image, ImageDraw, ImageFont

BG, LINE = (13, 13, 15), (52, 52, 58)
INK, MUTED, AMBER, DARK = (240, 238, 233), (150, 148, 143), (232, 165, 58), (20, 18, 14)

OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "auto-restart.png")

# Boxes are in the coordinates of the source screenshot, which is 1497x568.
# (box, badge corner, title, body)
# Corners were measured off the source rather than guessed. Two of these sit on
# a row that starts with a checkbox, so their badge goes to the right ("r"): a
# badge on the top-left corner covers the very control the callout points at.
CALLOUTS = [
    ((22, 22, 620, 78), "tr", "What it is doing now", ""),
    ((26, 146, 396, 170), "r", "Off until you turn it on", ""),
    ((26, 176, 1420, 222), "tr", "Your windows, not ours", ""),
    ((26, 236, 412, 258), "r", "Backup first, game too", ""),
    ((26, 266, 1478, 486), "tr", "Every message is yours", ""),
    ((78, 518, 164, 548), "r", "Try it without risk", ""),
]

LEGEND = [
    ("What it is doing now", "Whether it is on, how often it looks, and what the last check found"),
    ("Off until you turn it on", "Nothing restarts by itself until this is ticked, per server"),
    ("Your windows, not ours", "Warning time, countdown, and the minimum gap between two restarts"),
    ("Backup first, game too", "Snapshots before restarting, and watches the game build as well"),
    ("Every message is yours", "Four announcements, with :minutes, :seconds and :reason filled in"),
    ("Try it without risk", "Runs the check and reports what it found, restarting nothing"),
]

FONTS = [
    "/System/Library/Fonts/Supplemental/DejaVuSans%s.ttf",
    "/usr/share/fonts/truetype/dejavu/DejaVuSans%s.ttf",
    "/System/Library/Fonts/Supplemental/Arial%s.ttf",
]


def font(size, bold=False):
    for template in FONTS:
        path = template % ("-Bold" if bold else "")
        if os.path.exists(path):
            return ImageFont.truetype(path, size)
        path = template % (" Bold" if bold else "")
        if os.path.exists(path):
            return ImageFont.truetype(path, size)
    return ImageFont.load_default()


def main(src):
    shot = Image.open(src).convert("RGB")
    sw, sh = shot.size

    M, W = 60, 1600
    IW = W - 2 * M
    scale = IW / sw
    IH = int(sh * scale)
    shot = shot.resize((IW, IH), Image.LANCZOS)

    HEAD, LEG_ROW = 150, 76
    H = HEAD + IH + 46 + LEG_ROW * ((len(LEGEND) + 1) // 2) + 40
    im = Image.new("RGB", (W, H), BG)
    d = ImageDraw.Draw(im)

    d.text((M, 44), "AUTO-RESTART ON UPDATES", font=font(42, True), fill=INK)
    d.text((M, 102), "A mod updates on Steam, the server restarts itself before anyone is locked out",
           font=font(19), fill=MUTED)
    tag = "v2.5.0"
    tw = d.textbbox((0, 0), tag, font=font(19, True))[2]
    d.rectangle([W - M - tw - 28, 48, W - M, 86], fill=AMBER)
    d.text((W - M - tw - 14, 56), tag, font=font(19, True), fill=DARK)

    IX, IY = M, HEAD
    d.rectangle([IX - 2, IY - 2, IX + IW + 1, IY + IH + 1], outline=LINE, width=2)
    im.paste(shot, (IX, IY))

    R = 17
    for i, (box, corner, _t, _b) in enumerate(CALLOUTS, 1):
        x0, y0 = IX + int(box[0] * scale), IY + int(box[1] * scale)
        x1, y1 = IX + int(box[2] * scale), IY + int(box[3] * scale)
        d.rectangle([x0, y0, x1, y1], outline=AMBER, width=2)
        if corner == "r":
            bx, by = x1 + R + 6, (y0 + y1) // 2
        else:
            bx, by = (x0 if corner == "tl" else x1), y0
        # A ring of background behind the badge, so it reads as sitting on top of
        # the box rather than being swallowed by it.
        d.ellipse([bx - R - 3, by - R - 3, bx + R + 3, by + R + 3], fill=BG)
        d.ellipse([bx - R, by - R, bx + R, by + R], fill=AMBER)
        label = str(i)
        b = d.textbbox((0, 0), label, font=font(20, True))
        d.text((bx - b[2] / 2, by - b[3] / 2 - 2), label, font=font(20, True), fill=DARK)

    ly = IY + IH + 38
    COLW = (W - 2 * M) // 2
    for idx, (title, body) in enumerate(LEGEND):
        col, row = idx % 2, idx // 2
        x, y = M + col * COLW, ly + row * LEG_ROW
        d.ellipse([x, y + 4, x + 30, y + 34], fill=AMBER)
        b = d.textbbox((0, 0), str(idx + 1), font=font(18, True))
        d.text((x + 15 - b[2] / 2, y + 19 - b[3] / 2 - 2), str(idx + 1), font=font(18, True), fill=DARK)
        d.text((x + 46, y + 2), title, font=font(20, True), fill=INK)
        d.text((x + 46, y + 30), body, font=font(15), fill=MUTED)

    im.save(OUT)
    print("  geschreven:", OUT, im.size)


if __name__ == "__main__":
    main(sys.argv[1])
