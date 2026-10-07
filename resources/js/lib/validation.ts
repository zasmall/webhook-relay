/**
 * The first error for an array field, including per-item errors such as
 * `event_types.2`.
 */
export function arrayFieldError(
    errors: Record<string, string>,
    field: string,
): string | undefined {
    return (
        errors[field] ??
        Object.entries(errors).find(([key]) => key.startsWith(`${field}.`))?.[1]
    );
}
