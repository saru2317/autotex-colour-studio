export type Shade = {id:string;name:string;code:string;hex:string;visible:boolean};
export type Product = {id:string;name:string;filename:string;size:number;uploadedAt:string;stepKey:string;modelKey:string};
export type Catalog = {version:1;paletteRevision?:string;product:Product|null;colours:Shade[];updatedAt:string|null};
export const PALETTE_REVISION='autotex-standard-screen-2026-10-15';
// RAL public website swatches and dealer-published Pantone Solid Coated HEX references.
// Pantone coated finish is provisional; see palette-references.json for sources.
const referenceShades:Shade[]=[
  {
    "id": "shade-r3020",
    "name": "Traffic Red",
    "code": "RAL 3020",
    "hex": "#ab1519",
    "visible": true
  },
  {
    "id": "shade-r3015",
    "name": "Light Pink",
    "code": "RAL 3015",
    "hex": "#cc9ea4",
    "visible": true
  },
  {
    "id": "shade-p1777",
    "name": "Baby Pink",
    "code": "PANTONE 1777 C",
    "hex": "#fb637e",
    "visible": true
  },
  {
    "id": "shade-r5002",
    "name": "Ultramarine Blue",
    "code": "RAL 5002",
    "hex": "#193278",
    "visible": true
  },
  {
    "id": "shade-r1016",
    "name": "Sulfur Yellow",
    "code": "RAL 1016",
    "hex": "#e8e253",
    "visible": true
  },
  {
    "id": "shade-r1000",
    "name": "Green Beige",
    "code": "RAL 1000",
    "hex": "#c5bb8a",
    "visible": true
  },
  {
    "id": "shade-r4008",
    "name": "Signal Violet",
    "code": "RAL 4008",
    "hex": "#7c477d",
    "visible": true
  },
  {
    "id": "shade-p0226",
    "name": "Majestic Magenta",
    "code": "PANTONE 226 C",
    "hex": "#d0006f",
    "visible": true
  },
  {
    "id": "shade-p0172",
    "name": "Red Orange",
    "code": "PANTONE 172 C",
    "hex": "#fa4616",
    "visible": true
  },
  {
    "id": "shade-p0333",
    "name": "Mint Green",
    "code": "PANTONE 333 C",
    "hex": "#3cdbc0",
    "visible": true
  },
  {
    "id": "shade-r3016",
    "name": "Coral Red",
    "code": "RAL 3016",
    "hex": "#963d2f",
    "visible": true
  },
  {
    "id": "shade-r9005",
    "name": "Jet Black",
    "code": "RAL 9005",
    "hex": "#131516",
    "visible": true
  },
  {
    "id": "shade-p0375",
    "name": "Pista Green",
    "code": "PANTONE 375 C",
    "hex": "#97d700",
    "visible": true
  },
  {
    "id": "shade-p2562",
    "name": "Plum Lavender",
    "code": "PANTONE 2562 C",
    "hex": "#d7a9e3",
    "visible": true
  },
  {
    "id": "shade-r7005",
    "name": "Mouse Grey",
    "code": "RAL 7005",
    "hex": "#696d6b",
    "visible": true
  },
  {
    "id": "shade-r8012",
    "name": "Red Brown",
    "code": "RAL 8012",
    "hex": "#5c3128",
    "visible": true
  },
  {
    "id": "shade-p2975",
    "name": "Sky Blue",
    "code": "PANTONE 2975 C",
    "hex": "#99d6ea",
    "visible": true
  },
  {
    "id": "shade-p7515",
    "name": "Copper Brown",
    "code": "PANTONE 7515 C",
    "hex": "#c58b68",
    "visible": true
  },
  {
    "id": "shade-r1015",
    "name": "Light Ivory",
    "code": "RAL 1015",
    "hex": "#ded3b6",
    "visible": true
  },
  {
    "id": "shade-r6001",
    "name": "Emerald Green",
    "code": "RAL 6001",
    "hex": "#40693a",
    "visible": true
  }
];
export const defaultCatalog:Catalog={version:1,paletteRevision:PALETTE_REVISION,product:null,updatedAt:null,colours:referenceShades};

export function applyPaletteUpdate(catalog:Catalog):Catalog{
 if(catalog.paletteRevision===PALETTE_REVISION)return catalog;
 const fromChart=catalog.paletteRevision==='autotex-shades-2026-10-15';
 const references=new Map(referenceShades.map(c=>[c.id,c]));
 // Retain product, custom shades and admin visibility choices when correcting the chart.
 const colours=fromChart?catalog.colours.map(c=>{const ref=references.get(c.id);return ref?{...c,name:ref.name,code:ref.code,hex:ref.hex}:c;}):structuredClone(referenceShades);
 return {...catalog,paletteRevision:PALETTE_REVISION,colours};
}
