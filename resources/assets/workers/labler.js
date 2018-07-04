self.addEventListener('message', function(e) {
    let territories = e.data;
    let labels = [];

    for (let key in territories) {
        if (territories.hasOwnProperty(key)) {
            let territory = territories[key];

            if (territory.occupation) {
                labels.push(buildLabel(territory));
            }
        }
    }

    self.postMessage(labels);
}, false);

function buildLabel(territory) {
    let size = parseInt(territory.size / 200, 10);
    if (size > 40) size = 40;
    if (size < 10) size = 10;

    let avatar = '/images/default_avatar.png';
    if (territory.occupation.user.house && territory.occupation.user.house.image) {
        avatar = territory.occupation.user.house.image;
    } else if (!territory.occupation.user.house && territory.occupation.user.image) {
        avatar = territory.occupation.user.image;
    }

    return {
        territory: territory,
        x: territory.x - (size / 2),
        y: territory.y - (size / 2),
        avatar: avatar,
        size: size
    };
}