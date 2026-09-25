import L from 'leaflet';

/** Nigeria's bounding box (south-west, north-east), matching the tile extract. */
export const NIGERIA_BOUNDS = L.latLngBounds([4.2, 2.6], [13.9, 14.7]);

/** Approximate centre of Nigeria. */
export const NIGERIA_CENTER: L.LatLngTuple = [9.08, 8.68];
