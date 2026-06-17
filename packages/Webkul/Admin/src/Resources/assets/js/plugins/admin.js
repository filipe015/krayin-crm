/**
 * Generates a formatted date based on specified timezone.
 *
 * @param {string} dateString - The date value to be formatted.
 * @param {string} format - The format to be used for formatting the date.
 * @param {string} timezone - The timezone to use (e.g., 'America/Sao_Paulo').
 * @returns {string} - The formatted date string.
 */
formatDate: (dateString, format, timezone) => {
    // NÃO adiciona Z (UTC)
    const date = new Date(dateString.replace(' ', 'T'));

    const options = {
        timeZone: timezone,
    };

    const formatter = new Intl.DateTimeFormat("en-US", {
        ...options,
        hour12: false,
        year: "numeric",
        month: "numeric",
        day: "numeric",
        hour: "numeric",
        minute: "numeric",
        second: "numeric",
    });

    const parts = formatter.formatToParts(date);
    const dateParts = {};

    parts.forEach((part) => {
        if (part.type !== "literal") {
            dateParts[part.type] = part.value;
        }
    });

    const tzDay = parseInt(dateParts.day, 10);
    const tzMonth = parseInt(dateParts.month, 10);
    const tzYear = parseInt(dateParts.year, 10);
    const tzHour = parseInt(dateParts.hour, 10);
    const tzMinute = parseInt(dateParts.minute, 10);

    const formatters = {
        d: tzDay,
        DD: tzDay.toString().padStart(2, "0"),
        M: tzMonth,
        MM: tzMonth.toString().padStart(2, "0"),
        MMM: new Date(tzYear, tzMonth - 1, 1).toLocaleString(
            "en-US",
            { month: "short" }
        ),
        MMMM: new Date(tzYear, tzMonth - 1, 1).toLocaleString(
            "en-US",
            { month: "long" }
        ),
        yy: tzYear.toString().slice(-2),
        yyyy: tzYear,
        H: tzHour,
        HH: tzHour.toString().padStart(2, "0"),
        h: tzHour % 12 || 12,
        hh: (tzHour % 12 || 12).toString().padStart(2, "0"),
        m: tzMinute,
        mm: tzMinute.toString().padStart(2, "0"),
        A: tzHour < 12 ? "AM" : "PM",
    };

    return format.replace(
        /\b(?:d|DD|M|MM|MMM|MMMM|yy|yyyy|H|HH|h|hh|m|mm|A)\b/g,
        (match) => formatters[match]
    );
},
