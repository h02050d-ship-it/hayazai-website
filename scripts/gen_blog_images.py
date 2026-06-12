# -*- coding: utf-8 -*-
"""ブログ全記事のアイキャッチ画像を gpt-image-1 で一括生成する。

- スタイル: 線画水彩風（サイト共通の決定事項）
- 出力: images/blog/<slug>.jpg（1200x630 = OGP標準・記事カード兼用）
- 生成: 1536x1024 medium → 中央クロップで1200x630、JPEG q82
"""
import io
import os
import sys
import time
import base64
from pathlib import Path

from openai import OpenAI
from PIL import Image

OUT_DIR = Path(__file__).resolve().parent.parent / "images" / "blog"
OUT_DIR.mkdir(parents=True, exist_ok=True)

STYLE = (
    "手描きの繊細な線画に淡い水彩で彩色したイラスト。"
    "温かみのある和の雰囲気、背景は生成り色、木材は淡いベージュから飴色。"
    "余白を生かした上品な構図。文字・ロゴ・数字は一切入れない。横長。"
)

IMAGES = {
    "hinoki-demerit": "明るいリビングの桧の無垢フローリング。床に座って木の床を手のひらで確かめる人。小さな傷も含めて木と暮らす正直な日常の空気感。",
    "muku-vs-fukugou": "無垢フローリングの一枚板の断面と、複合フローリングの積層断面を並べて見比べる構図。木の断面の年輪と層構造がわかるイラスト。",
    "sugi-vs-hinoki": "杉の床板サンプルと桧の床板サンプルを左右に並べた構図。杉は赤みのある木目、桧は白く明るい木目。",
    "uni-opc-ranjaku": "長さの違う無垢フローリングの板を3種類並べた構図。継ぎ目のない長い一枚物、縦に継いだ板、長さがばらばらの板。",
    "thickness-15-30": "厚みの違う2枚の無垢フローリング板を断面が見えるように重ねた構図。下に木の根太(角材)。",
    "finish-mutosou-oil-urethane": "無垢の床に布でオイルを塗り込む手元。そばに小さなオイル缶と布。木目に艶が出ていく様子。",
    "diy-ng-10": "DIYで床張りの途中、立ち止まって考える人。床に並んだ板と工具、片手にボンドの容器。注意深い雰囲気。",
    "kasanebari-diy": "古い床の上に新しい無垢フローリングを一枚重ねて張っている手元。そばに部屋のドアの下端。",
    "tatami-to-flooring": "和室の畳を半分剥がし、半分が新しい桧フローリングに変わりつつある部屋。改装途中の様子。",
    "kugi-screw-bond": "無垢フローリング施工の道具の静物画。フロア釘、ビス、ボンドのカートリッジ、金槌、インパクトドライバーを板の上に並べた構図。",
    "mansion-ll45": "マンションの部屋で無垢フローリングの下に遮音マットと合板を重ねた床の断面がわかるイラスト。窓の外に集合住宅。",
    "hekomi-iron-repair": "無垢の床の小さな凹みに濡れた布を当て、アイロンをかけて補修する手元。湯気がふわっと立つ。",
    "sukima-sori-tsukiage": "無垢フローリングの板と板の間にできたわずかな隙間のクローズアップ。冬の窓辺の光。木が呼吸している静かな雰囲気。",
    "shimi-kabi-cleaning": "無垢の床のシミをやさしく拭き取る手元。固く絞った雑巾と桶。清潔で前向きな雰囲気。",
    "robot-cleaner-kaden": "桧の無垢フローリングの上を走る丸いロボット掃除機。そばに加湿器と観葉植物。現代の和の暮らし。",
    "diy-flooring-tips": "明るい部屋で桧の無垢フローリングを張るDIYの様子。膝をついて板をはめ込む人、そばにゴムハンマー。",
    "how-to-choose-grade": "節のある板、小さな節の板、節のない板の3枚の桧フローリングを並べて見比べる構図。木目と節の表情の違い。",
    "hinoki-flooring-care": "桧の無垢フローリングを乾いた布で拭く手元。窓からの朝の光、清々しい空気感。",
    "flooring-vs-panel": "部屋の床に張られたフローリングと、壁に張られた羽目板が同時に見える部屋の隅の構図。木に包まれた空間。",
    "hinoki-benefits": "桧の森の木漏れ日と、白く美しい桧の無垢フローリングの部屋が重なり合うイメージ。森から床へつながる物語性。",
    "how-many-sheets": "部屋の床にメジャーを伸ばして採寸する手元。そばに方眼紙の間取り図と鉛筆、積まれたフローリングの板。",
    "shizuoka-hinoki-shop": "天竜川と杉桧の人工美林の山並み、手前に小さな製材所。静岡の木の産地の風景。",
}


def main():
    only = set(sys.argv[1:])  # 引数でslug指定すればそれだけ再生成
    client = OpenAI()
    ok, ng = [], []
    for slug, scene in IMAGES.items():
        if only and slug not in only:
            continue
        dest = OUT_DIR / f"{slug}.jpg"
        if dest.exists() and not only:
            print(f"[SKIP] {slug} (exists)")
            ok.append(slug)
            continue
        prompt = STYLE + " 題材: " + scene
        for attempt in (1, 2):
            try:
                resp = client.images.generate(
                    model="gpt-image-1", prompt=prompt,
                    size="1536x1024", n=1, quality="medium",
                )
                data = base64.b64decode(resp.data[0].b64_json)
                img = Image.open(io.BytesIO(data))
                if img.mode == "RGBA":
                    bg = Image.new("RGB", img.size, (255, 255, 255))
                    bg.paste(img, mask=img.split()[3])
                    img = bg
                # 中央クロップ 1200x630
                w, h = img.size
                tr = 1200 / 630
                if w / h > tr:
                    nw = int(h * tr)
                    img = img.crop(((w - nw) // 2, 0, (w - nw) // 2 + nw, h))
                else:
                    nh = int(w / tr)
                    img = img.crop((0, (h - nh) // 2, w, (h - nh) // 2 + nh))
                img = img.resize((1200, 630), Image.LANCZOS)
                img.convert("RGB").save(dest, "JPEG", quality=82, optimize=True)
                print(f"[OK] {slug} -> {dest}")
                ok.append(slug)
                break
            except Exception as e:
                print(f"[ERR] {slug} attempt {attempt}: {e}")
                if attempt == 2:
                    ng.append(slug)
                else:
                    time.sleep(5)
    print(f"\n[DONE] success={len(ok)} failed={len(ng)}")
    if ng:
        print("failed slugs: " + " ".join(ng))
        sys.exit(1)


if __name__ == "__main__":
    main()
