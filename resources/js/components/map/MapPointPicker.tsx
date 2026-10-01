import type { Geometry } from 'geojson';
import { X } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import BuffaloMap from '@/components/map/BuffaloMap';
import type { MapCircle, MapMarker } from '@/components/map/BuffaloMap';
import { NIGERIA_BOUNDS } from '@/components/map/nigeria';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type Coordinates = { latitude: string; longitude: string };

/** Whether both coordinates are set and inside Nigeria's bounding box. */
export function coordinatesProblem(value: Coordinates): string | null {
    if (value.latitude === '' && value.longitude === '') {
        return null;
    }

    const latitude = Number(value.latitude);
    const longitude = Number(value.longitude);

    if (
        value.latitude === '' ||
        value.longitude === '' ||
        Number.isNaN(latitude) ||
        Number.isNaN(longitude)
    ) {
        return 'Enter both latitude and longitude, or neither.';
    }

    return NIGERIA_BOUNDS.contains([latitude, longitude])
        ? null
        : 'The location must be within Nigeria.';
}

/**
 * Pick a point: click the map to set it, drag the marker to adjust, or type
 * the coordinates. Coordinates are checked against Nigeria's bounds here;
 * the server re-validates them (and the LGA boundary, when known).
 */
export default function MapPointPicker({
    value,
    onChange,
    center,
    zoom,
    markers,
    outline,
    circles,
    errors = {},
    className = 'h-80',
}: {
    value: Coordinates;
    onChange: (value: Coordinates) => void;
    center?: [number, number];
    zoom?: number;
    /** Other points for context (e.g. neighbouring communities). */
    markers?: MapMarker[];
    /** The LGA's boundary (MapView outline), for orientation. */
    outline?: Geometry | null;
    /** Areas drawn around the point (e.g. a flashpoint's radius). */
    circles?: MapCircle[];
    errors?: { latitude?: string; longitude?: string };
    className?: string;
}) {
    const [touched, setTouched] = useState(false);
    const problem = coordinatesProblem(value);
    const point: [number, number] | null =
        problem === null && value.latitude !== '' && value.longitude !== ''
            ? [Number(value.latitude), Number(value.longitude)]
            : null;

    return (
        <div className="space-y-3" data-test="map-point-picker">
            <BuffaloMap
                center={point ?? center}
                zoom={point ? Math.max(zoom ?? 6, 12) : zoom}
                className={`${className} w-full rounded-lg border`}
                markers={markers}
                outline={outline}
                circles={circles}
                picker={{
                    value: point,
                    onChange: ([latitude, longitude]) => {
                        setTouched(true);
                        onChange({
                            latitude: String(latitude),
                            longitude: String(longitude),
                        });
                    },
                }}
            />
            <p className="text-xs text-muted-foreground">
                Click the map to set the location; drag the marker to adjust it.
            </p>
            <div className="grid grid-cols-[1fr_1fr_auto] items-end gap-2">
                <div className="grid gap-2">
                    <Label htmlFor="latitude">Latitude</Label>
                    <Input
                        id="latitude"
                        inputMode="decimal"
                        placeholder="e.g. 6.5244"
                        value={value.latitude}
                        onChange={(event) => {
                            setTouched(true);
                            onChange({
                                ...value,
                                latitude: event.target.value,
                            });
                        }}
                    />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="longitude">Longitude</Label>
                    <Input
                        id="longitude"
                        inputMode="decimal"
                        placeholder="e.g. 3.3792"
                        value={value.longitude}
                        onChange={(event) => {
                            setTouched(true);
                            onChange({
                                ...value,
                                longitude: event.target.value,
                            });
                        }}
                    />
                </div>
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => onChange({ latitude: '', longitude: '' })}
                    disabled={value.latitude === '' && value.longitude === ''}
                >
                    <X /> Clear
                </Button>
            </div>
            <InputError
                message={
                    (touched ? problem : null) ??
                    errors.latitude ??
                    errors.longitude ??
                    undefined
                }
            />
        </div>
    );
}
